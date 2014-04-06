<?php

date_default_timezone_set('America/Los_Angeles');

require_once('../../config.php');
require_once('vendor/autoload.php');
require_once('vendor/php-ga/autoload.php');

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use UnitedPrototype\GoogleAnalytics;

class DB extends RedBean_Facade{};
function initDB() {
    DB::setup('mysql:host=' . HTTPEBBLE_DB_HOST . ';dbname=' . HTTPEBBLE_DB_NAME, HTTPEBBLE_DB_USER, HTTPEBBLE_DB_PASSWORD);
    DB::$writer->setUseCache(true);
    DB::freeze();
}

function trackHit($url, $title) {
    // Initilize GA Tracker
    $config = new GoogleAnalytics\Config();
    $config->setAnonymizeIpAddresses(true);
    $tracker = new GoogleAnalytics\Tracker('MO-43643039-1', 'ofkorth.net', $config);

    $uas = '';
    if(isset($_SERVER['HTTP_USER_AGENT']))
        $uas = $_SERVER['HTTP_USER_AGENT'];

    // Assemble Visitor information
    $visitor = new GoogleAnalytics\Visitor();
    $visitor->setIpAddress($_SERVER['REMOTE_ADDR']);
    $visitor->setUserAgent($uas);
    $visitor->setScreenResolution('1024x768'); // Default since we can't detect

    // Assemble Session information
    $session = new GoogleAnalytics\Session();

    // Assemble Page information
    $page = new GoogleAnalytics\Page($url);
    $page->setTitle($title);

    // Track page view
    $tracker->trackPageview($page, $session, $visitor);
}

$app = new Application();

// default route
$app->get('/', function(Application $app) {
    trackHit('/pebble/', 'Root redirect');
    return $app->redirect('http://lukekorth.com/');
});

$app->error(function (\Exception $e, $code) {
    return new Response();
});

$app->get('/watchfaces', function(Application $app) {
	trackHit('/watchfaces-goodbye-httpebble', 'Watchfaces - Goodbye httpebble');
	return $app->redirect('http://lukekorth.com/blog/goodbye-httpebble/');
});

$app->post('/register', function(Application $app, Request $request) {
    initDB();

    $data = json_decode($request->request->get('data'), true);

    if(empty($data['userId']) || empty($data['userToken']) || empty($data['gcmId']))
        $app->abort(400);
    else {
        $user = DB::findOne('user', ' userid = :userid ', array(':userid' => $data['userId']));

        if($user == null) {
            $user = DB::dispense('user');
            $user->notifications = 0;
            $user->ifttt = 0;
        }

        $user->userid = $data['userId'];
        $user->usertoken = $data['userToken'];
        $user->gcmid = $data['gcmId'];

        DB::store($user);

        trackHit('/pebble/register', 'Pebble Connect GCM Register');

        return new Response();
    }
});

$app->post('/send', function(Application $app, Request $request) {
    if($request->request->get('type') == 'notification') {
        initDB();

        $userId = $request->request->get('userId');

        if(empty($userId))
            $app->abort(400);

        $user = DB::findOne('user', ' userid = :userid ', array(':userid' => $userId));

        if($user == null)
            $app->abort(404);

        if($user->usertoken != $request->request->get('userToken'))
            $app->abort(400);

        $notification = array();
        $notification['type'] = 'notification';
        $notification['title'] = $request->request->get('title');
        $notification['body'] = $request->request->get('body');

        $sender = new PHP_GCM\Sender(HTTPEBBLE_GCM_KEY);
        $message = new PHP_GCM\Message('', $notification);

        try {
            $result = $sender->send($message, $user->gcmid, 3);

            $user->notifications = $user->notifications + 1;
            DB::store($user);
        } catch (\InvalidArgumentException $e) {
            $app->abort(500);
        } catch (PHP_GCM\InvalidRequestException $e) {
            $app->abort($e->getHttpStatusCode());
        } catch (\Exception $e) {
            $app->abort(500);
        }

        trackHit('/pebble/send', 'RESTful API');

        return new Response();
    } else {
        $app->abort(400);
    }
});

$app->post('/xmlrpc.php', function() {
    initDB();

	$xml = simplexml_load_string(file_get_contents('php://input'));
	switch($xml->methodName) {
		//wordpress blog verification
		case 'mt.supportedMethods':
			return success('metaWeblog.getRecentPosts');
			break;
		//first authentication request from ifttt
		case 'metaWeblog.getRecentPosts':
			//send a blank blog response
			//this also makes sure that the channel is never triggered
			return success('<array><data></data></array>');
			break;
		case 'metaWeblog.newPost':
			//@see http://codex.wordpress.org/XML-RPC_WordPress_API/Posts#wp.newPost
			$obj = new stdClass;
			//get the parameters from xml
			$obj->user = (string)$xml->params->param[1]->value->string;
			$obj->pass = (string)$xml->params->param[2]->value->string;

			//@see content in the wordpress docs
			$content = $xml->params->param[3]->value->struct->member;
			foreach($content as $data) {
				switch((string)$data->name) {
					//we use the tags field for providing webhook URL
					case 'mt_keywords':
						$url = $data->xpath('value/array/data/value/string');
						$url = (string)$url[0];
						break;

					//the passed categories are parsed into an array
					case 'categories':
						$categories=array();
						foreach($data->xpath('value/array/data/value/string') as $cat)
							array_push($categories,(string)$cat);
						$obj->categories = $categories;
						break;

					//this is used for title/description
					default:
						$obj->{$data->name} = (string)$data->value->string;
				}
			}

            $user = DB::findOne('user', ' userid = :userid ', array(':userid' => $obj->user));

            if($user == null)
                return failure(404);

            if($user->usertoken != $obj->pass)
                return failure(400);

            $notification = array();
            $notification['type'] = 'notification';
            $notification['title'] = $obj->title;
            $notification['body'] = $obj->description;

            $sender = new PHP_GCM\Sender(HTTPEBBLE_GCM_KEY);
            $message = new PHP_GCM\Message('', $notification);

            try {
                $result = $sender->send($message, $user->gcmid, 3);

                $user->ifttt = $user->ifttt + 1;
                DB::store($user);

                trackHit('/pebble/xmlrpc.php', 'IFTTT Endpoint');

                return success('<string>200</string>');
            } catch (\InvalidArgumentException $e) {
                return failure(500);
            } catch (PHP_GCM\InvalidRequestException $e) {
                return failure($e->getHttpStatusCode());
            } catch (\Exception $e) {
                return failure(500);
            }
	}
});

function success($innerXML) {
	$xml =  <<<EOD
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
      $innerXML
      </value>
    </param>
  </params>
</methodResponse>

EOD;

    return output($xml);
}

function output($xml) {
    $response = new Response($xml);
    $response->headers->set('Connection', 'close');
    $response->headers->set('Content-Length', strlen($xml));
    $response->headers->set('Content-Type', 'text/xml');
    $response->headers->set('Date', date('r'));

    return $response;
}

function failure($status) {
$xml= <<<EOD
<?xml version="1.0"?>
<methodResponse>
  <fault>
    <value>
      <struct>
        <member>
          <name>faultCode</name>
          <value><int>$status</int></value>
        </member>
        <member>
          <name>faultString</name>
          <value><string>Request was not successful.</string></value>
        </member>
      </struct>
    </value>
  </fault>
</methodResponse>

EOD;

    return output($xml);
}

$app->run();