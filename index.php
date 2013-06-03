<?php

require_once('../../config.php');
require_once('vendor/autoload.php');

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DB extends RedBean_Facade{};
function initDB() {
    DB::setup('mysql:host=' . HTTPEBBLE_DB_HOST . ';dbname=' . HTTPEBBLE_DB_NAME, HTTPEBBLE_DB_USER, HTTPEBBLE_DB_PASSWORD);
    DB::$writer->setUseCache(true);
    //R::freeze();
}

$app = new Application();
$app['debug'] = true;

// default route
$app->get('/', function(Application $app) {
    return $app->redirect('http://lukekorth.com/blog/category/pebble/');
});

$app->get('/watchfaces', function(Application $app) {
    $html = <<<EOD
<!doctype html>
<!-- design from http://pebble-static.s3.amazonaws.com/watchfaces/index.html -->
<html>
<head>
    <link rel="stylesheet" href="/assets/stylesheet.css" type="text/css" charset="utf-8" />
</head>
<body>
<div style="width: 320px; margin: 0 auto;">
	<h1 id="watchfaces">Watchfaces</h1>
	<h3 id="updated">Updated 6/2</h3>
	<br>
	<ul id="watchface-list">
	    <a href="http://builds.cloudpebble.net/c/a/ca37c0d2ca8f4fd9ad53c23616c06422/watchface.pbw">
			<div class="cell">
				<li style="background: url('/assets/weather-watch.jpg') no-repeat center left">Weather Watch<br>by: Katharine</li>
			</div>
		</a>

		<a href="http://builds.cloudpebble.net/b/5/b59acb1e6fe14d678d420dc02b325f37/watchface.pbw">
			<div class="cell">
				<li style="background: url('/assets/roboto-weather.jpg') no-repeat center left">Roboto Weather<br>by: Zone-MR</li>
			</div>
		</a>

		<a href="http://www.mypebblefaces.com/download.php?fID=3735&version=1.7&uID=3263&link=1">
			<div class="cell">
				<li style="background: url('/assets/futura-weather.jpg') no-repeat center left">Futura<br>by: Niknam</li>
			</div>
		</a>
		<a href="http://www.mypebblefaces.com/download.php?fID=3777&version=1.7&uID=3263&link=2&sub=1">
			<div class="cell">
				<li style="background: url('/assets/futura-weather.jpg') no-repeat center left">Futura (no vibration alert)<br>by: Niknam</li>
			</div>
		</a>
	</ul>
</div>
</body>
</html>

EOD;

    return new Response($html);
});

$app->post('/register', function(Application $app, Request $request) {
    initDB();

    $data = json_decode($request->request->get('data'), true);

    if(empty($data['userId']) || empty($data['userToken']) || empty($data['gcmId']))
        $app->abort(400);
    else {
        $user = DB::findOne('user', ' userid = :userid ', array(':userid' => $data['userId']));

        if($user == null)
            $user = DB::dispense('user');

        $user->userid = $data['userId'];
        $user->usertoken = $data['userToken'];
        $user->gcmid = $data['gcmId'];

        DB::store($user);

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
        } catch (\InvalidArgumentException $e) {
            $app->abort(500);
        } catch (PHP_GCM\InvalidRequestException $e) {
            $app->abort($e->getHttpStatusCode());
        } catch (\Exception $e) {
            $app->abort(500);
        }

        return new Response();
    } else {
        $app->abort(400);
    }
});

$app->post('/xmlrpc.php', function(Application $app, Request $request) {
    initDB();

	error_reporting(-1);
	ini_set('display_errors',1);
	$request_body = file_get_contents('php://input');
	$xml = simplexml_load_string($request_body);

	switch($xml->methodName)
	{

		//wordpress blog verification
		case 'mt.supportedMethods':
			success('metaWeblog.getRecentPosts');
			break;
		//first authentication request from ifttt
		case 'metaWeblog.getRecentPosts':
			//send a blank blog response
			//this also makes sure that the channel is never triggered
			success('<array><data></data></array>');
			break;

		case 'metaWeblog.newPost':
			//@see http://codex.wordpress.org/XML-RPC_WordPress_API/Posts#wp.newPost
			$obj = new stdClass;
			//get the parameters from xml
			$obj->user = (string)$xml->params->param[1]->value->string;
			$obj->pass = (string)$xml->params->param[2]->value->string;

			//@see content in the wordpress docs
			$content = $xml->params->param[3]->value->struct->member;
			foreach($content as $data)
			{
				switch((string)$data->name)
				{
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

			//Make the webrequest
			//Only if we have a valid url
			if(valid_url($url,true))
			{
				// Load Requests Library
				include('requests/Requests.php');
				Requests::register_autoloader();

				$headers = array('Content-Type' => 'application/json');
				$response = Requests::post($url, $headers, json_encode($obj));

				if($response->success)
					success('<string>'.$response->status_code.'</string>');
				else
					failure($response->status_code);
			}
			else
			{
				//since the url was invalid, we return 400 (Bad Request)
				failure(400);
			}
			
	}
});

/** Copied from wordpress */

function success($innerXML)
{
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
	output($xml);
}

function output($xml){
	$length = strlen($xml);
	header('Connection: close');
	header('Content-Length: '.$length);
	header('Content-Type: text/xml');
	header('Date: '.date('r'));
	echo $xml;
	exit;
}

function failure($status){
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
output($xml);
}

/** Used from drupal */
function valid_url($url, $absolute = FALSE) {
  if ($absolute) {
    return (bool) preg_match("
      /^                                                      # Start at the beginning of the text
      (?:https?):\/\/                                # Look for ftp, http, https or feed schemes
      (?:                                                     # Userinfo (optional) which is typically
        (?:(?:[\w\.\-\+!$&'\(\)*\+,;=]|%[0-9a-f]{2})+:)*      # a username or a username and password
        (?:[\w\.\-\+%!$&'\(\)*\+,;=]|%[0-9a-f]{2})+@          # combination
      )?
      (?:
        (?:[a-z0-9\-\.]|%[0-9a-f]{2})+                        # A domain name or a IPv4 address
        |(?:\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\])         # or a well formed IPv6 address
      )
      (?::[0-9]+)?                                            # Server port number (optional)
      (?:[\/|\?]
        (?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})   # The path and query (optional)
      *)?
    $/xi", $url);
  }
  else {
    return (bool) preg_match("/^(?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})+$/i", $url);
  }
}

$app->run();