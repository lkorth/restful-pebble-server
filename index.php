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
    DB::freeze();
}

$app = new Application();

// default route
$app->get('/', function(Application $app) {
    return $app->redirect('http://lukekorth.com/');
});

$app->error(function (\Exception $e, $code) {
    return new Response();
});

$app->get('/watchfaces', function(Application $app) {
    $html = <<<EOD
<!doctype html>
<!-- design from http://pebble-static.s3.amazonaws.com/watchfaces/index.html -->
<html>
<head>
    <link rel="stylesheet" href="/pebble/assets/stylesheet.css" type="text/css" charset="utf-8" />
</head>
<body>
<div style="width: 320px; margin: 0 auto;">
	<h1 id="watchfaces">Watchfaces</h1>
	<h3 id="updated">Updated 6/16</h3>
	<br>
	<ul id="watchface-list">
	    <a href="http://builds.cloudpebble.net/d/9/d9f4525aa8124c4ba2f44ebfb14dafae/watchface.pbw">
			<div class="cell">
				<li style="background: url('/pebble/assets/weather-watch.jpg') no-repeat center left">Weather Watch<br>by: Katharine</li>
			</div>
		</a>

		<a href="http://builds.cloudpebble.net/2/8/28e616a40416495caf6150059156c2d9/watchface.pbw">
			<div class="cell">
				<li style="background: url('/pebble/assets/roboto-weather.jpg') no-repeat center left">Roboto Weather (Celsius - Yahoo Weather Mod)<br>by: Zone-MR</li>
			</div>
		</a>
		<a href="http://builds.cloudpebble.net/5/2/52e25b48e60a47259a9e4a3713570066/watchface.pbw">
			<div class="cell">
				<li style="background: url('/pebble/assets/roboto-weather.jpg') no-repeat center left">Roboto Weather (Fahrenheit - Yahoo Weather Mod)<br>by: Zone-MR</li>
			</div>
		</a>

		<a href="http://www.mypebblefaces.com/download_app?cID=2905">
			<div class="cell">
				<li style="background: url('/pebble/assets/futura-weather.jpg') no-repeat center left">Futura<br>by: Niknam</li>
			</div>
		</a>
		<a href="http://www.mypebblefaces.com/download_app?cID=3822">
			<div class="cell">
				<li style="background: url('/pebble/assets/futura-weather.jpg') no-repeat center left">Futura (no vibration alert)<br>by: Niknam</li>
			</div>
		</a>

		<a href="http://www.mypebblefaces.com/download.php?fID=3217&version=1.1.6&uID=1444&link=1">
			<div class="cell">
				<li style="background: url('/pebble/assets/pebsona4.png') no-repeat center left">Pebsona 4<br>by: Spencer Johnson</li>
			</div>
		</a>
	</ul>
</div>
</body>
</html>

EOD;

    return new Response($html);
});

$app->post('/weather', function(Application $app) {
    $payload = json_decode(file_get_contents('php://input'), true);
    if(!$payload)
        return $app->abort(400);

    $lat = round($payload[1] / 10000, 3);
    $long = round($payload[2] / 10000, 3);
    $units = $payload[3];

    $success = false;
    $woeid = apc_fetch("$lat$long", $success);

    if(!$success) {
        $flickrResponse = get_data('http://api.flickr.com/services/rest/?method=flickr.places.findByLatLon&format=json&api_key=' . FLICKR_KEY . '&lat=' . $lat . '&lon=' . $long);
        $flickrResponse = json_decode(substr($flickrResponse, 14, strlen($flickrResponse) - 15), true);
        $woeid = $flickrResponse['places']['place'][0]['woeid'];

        apc_store("$lat$long", $woeid);
    }

    $xml = simplexml_load_file('http://weather.yahooapis.com/forecastrss?w=' . $woeid . '&u=' . $units);
    $xml->registerXPathNamespace('yweather', 'http://xml.weather.yahoo.com/ns/rss/1.0');
    $condition = $xml->channel->item->xpath('yweather:condition');

    // yahoo code => watch face icon id // yahoo condition => watch face condition
    $icons = array(
        0 => 5, //tornado => wind
        1 => 5, //tropical storm => wind
        2 => 5, //hurricane => wind
        3 => 10, //severe thunderstorms => thunder
        4 => 10, //thunderstorms => thunder
        5 => 11, //mixed rain and snow => rain-snow
        6 => 12, //mixed rain and sleet => rain-sleet
        7 => 13, //mixed snow and sleet => snow-sleet
        8 => 2, //freezing drizzle => rain
        9 => 2, //drizzle => rain
        10 => 2, //freezing rain => rain
        11 => 2, //showers => rain
        12 => 2, //showers => rain
        13 => 3, //snow flurries => snow
        14 => 3, //light snow showers => snow
        15 => 3, //blowing snow => snow
        16 => 3, //snow => snow
        17 => 4, //hail => sleet
        18 => 4, //sleet => sleet
        19 => 6, //dust => fog
        20 => 6, //foggy => fog
        21 => 6, //haze => fog
        22 => 6, //smoky => fog
        23 => 5, //blustery => wind
        24 => 5, //windy => wind
        25 => 14, //cold => cold
        26 => 7, //cloudy => cloudy
        27 => 9, //mostly cloudy (night) => partly-cloudy-night
        28 => 8, //mostly cloudy (day) => partly-cloudy-day
        29 => 9, //partly cloudy (night) => partly-cloudy-night
        30 => 8, //partly cloudy (day) => partly-cloudy-day
        31 => 1, //clear (night) => clear-night
        32 => 0, //sunny => clear-day
        33 => 9, //fair (night) => partly-cloudy-night
        34 => 8, //fair (day) => partly-cloudy-day
        35 => 12, //mixed rain and hail => rain-sleet
        36 => 15, //hot => hot
        37 => 10, //isolated thunderstorms => thunder
        38 => 10, //scattered thunderstorms => thunder
        39 => 10, //scattered thunderstorms => thunder
        40 => 2, //scattered showers => rain
        41 => 3, //heavy snow => snow
        42 => 3, //scattered snow showers => snow
        43 => 3, //heavy snow => snow
        44 => 8, //partly cloudy => partly-cloudy-day
        45 => 10, //thundershowers => thunder
        46 => 3, //snow showers => snow
        47 => 10, //isolated thundershowers => thunder
        3200 => 16 //not available
    );

    $data = array();

    $data[1] = array('b', $icons[(int) $condition[0]['code']]);
    $data[2] = array('s', round($condition[0]['temp']));

    return $app->json($data, 200, array(
        'Expires' => 'Mon, 26 Jul 1997 05:00:00 GMT',
        'Last-Modified' => gmdate("D, d M Y H:i:s") . " GMT",
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Cache-Control', 'post-check=0, pre-check=0',
        'Pragma' => 'no-cache',
        'ETag' => md5(json_encode($data))
    ));
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

        return new Response();
    } else {
        $app->abort(400);
    }
});

$app->post('/xmlrpc.php', function(Application $app) {
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

function get_data($url) {
    $ch = curl_init();
    $timeout = 25;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

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
