<html>
  <head>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="route.css">

    <link rel="stylesheet" href="css/weather-icons.min.css">
    <link rel="stylesheet" href="css/weather-icons-wind.min.css">

    <link href='https://api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/leaflet.fullscreen.css' rel='stylesheet' />

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://unpkg.com/polyline-encoded@0.0.9/Polyline.encoded.js"></script>
    <script src="https://unpkg.com/apexcharts@3.54.0/dist/apexcharts.js"></script>
    <script src="https://unpkg.com/leaflet-geometryutil@0.10.2/src/leaflet.geometryutil.js"></script>
    <script src="https://unpkg.com/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.js"></script>

    <script src='https://api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/Leaflet.fullscreen.min.js'></script>
  </head>
<body>

<?php
include 'vendor/autoload.php';

require_once 'lib/twgeojson-simplify.php';
require_once 'lib/PolylineEncoder.php';

require_once 'fetch_weather.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

require 'hh_session_check.php';

$ride_id = $_GET["ride_id"];

echo "\n<header>";
  include 'header.php';
echo "</header>\n";

$servername = $_ENV['MYSQL_SERVER_NAME'];
$username = $_ENV['MYSQL_USERNAME'];
$password = $_ENV['MYSQL_PASSWORD'];
$dbname = $_ENV['MYSQL_DATABASE'];

$hh_user_id = $_SESSION['HH_USER_ID'];
$hh_access_token  = $_SESSION['HH_ACCESS_TOKEN'];

use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => $_ENV['HH_BASE_URL'],
]);

// Create connection
try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
} catch (PDOException $e) {
    die('Error in connection: ' . $e->getMessage());
}

function update_hh_ride_field($ride_id, $field_name, $field_value) {

    global $db;
    global $hh_user_id;

    $sql = "UPDATE hh_rides SET $field_name=:field_value WHERE id=:id AND user_id=:user_id";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if(!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }
    $stmt->bindValue(':field_value', $field_value);
    $stmt->bindValue(':id', $ride_id);
    $stmt->bindValue(':user_id', $hh_user_id);

    if($stmt->execute())  {
      echo "Updated ride $field_name <br/>";
     } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}

function update_hh_ride_polyline($ride_id, $polyline) {
    return update_hh_ride_field($ride_id, "polyline", $polyline);
}

function update_hh_ride_summary_polyline($ride_id, $summary_polyline) {
    return update_hh_ride_field($ride_id, "summary_polyline", $summary_polyline);
}

function update_hh_ride_tiles($ride_id, $tiles) {
    return update_hh_ride_field($ride_id, "tiles", $tiles);
}

function update_hh_ride_record_data($ride_id, $record_data_json) {
    return update_hh_ride_field($ride_id, "record_data", $record_data_json);
}

function update_hh_ride_pauses($ride_id, $pauses) {
    return update_hh_ride_field($ride_id, "pauses", $pauses);
}

function update_hh_ride_climbs($ride_id, $climbs) {
    return update_hh_ride_field($ride_id, "climbs", $climbs);
}


$stmt = $db->prepare("SELECT * FROM hh_rides WHERE id = :id AND user_id = :user_id");
if (!$stmt) {
    die("Can't prepare statement: " . $db->errorInfo());
}
$stmt->bindValue(':id', $ride_id);
$stmt->bindValue(':user_id', $hh_user_id);

$result = $stmt->execute();
if($result) {

    $ride = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!empty($ride)) {

        if (empty($ride['polyline']) || empty($ride['tiles']) || empty($ride['record_data']) || empty($ride['climbs']) || empty($ride['pauses'])) {

            // fetch the full ride details
            $response = $client->request('GET', "/v1/users/$hh_user_id/activities/$ride_id/details", [
                'headers' => [
                        'Authorization' => 'Bearer '. $hh_access_token
                ]
            ]);

            if($response->getStatusCode() == 200) {

                $ride_details = json_decode($response->getBody(), true);

                if (empty($ride['polyline'] )) {

                    $ride['polyline'] = $ride_details['activityData']['polyline'];

                    update_hh_ride_polyline($ride_id, $ride['polyline']);
                }

                if(empty($ride['tiles']) && !empty($ride['polyline'])) {

                    $ride_points = PolylineEncoder::decodeValue($ride['polyline']);
                    $tiles = [];
                    $zoom = 14;
                    foreach ($ride_points as $rp) {

                        $lat = $rp['x'];  // counterintuitive mapping of x,y
                        $lon = $rp['y'];

                        $xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
                        $ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));

                        $tile = $xtile . '-' . $ytile;

                        if(!array_key_exists($tile, $tiles)) {
                            $tiles[$tile] = TRUE;
                        }
                    }
                    ksort($tiles);
                    $ride['tiles'] = join(',', array_keys($tiles));
                    update_hh_ride_tiles($ride_id, $ride['tiles']);
                }
            }

            if (empty($ride['record_data'] )) {

                $ride['record_data'] = $ride_details['recordData'];  // decoded as objects/arrays

                update_hh_ride_record_data($ride_id, json_encode($ride['record_data']));
            }

            if (empty($ride['climbs'] )) {

                $ride['climbs'] = $ride_details['activityData']['climbs'];  // decoded as objects/arrays

                update_hh_ride_climbs($ride_id, json_encode($ride['climbs']));
            }

            if (empty($ride['pauses'] )) {

                $ride['pauses'] = $ride_details['activityData']['laps'][0]['pauses'];  // decoded as objects/arrays

                update_hh_ride_pauses($ride_id, json_encode($ride['pauses']));
            }

        }
        if (empty($ride['weather'] && !empty($ride['polyline']) )) {

            $ride_points = PolylineEncoder::decodeValue($ride['polyline']);

            $start_latitude = $ride_points[0]['x'];  // counterintuitive mapping of x,y
            $start_longitude = $ride_points[0]['y'];

            fetchWeather($db, $ride_id, $hh_user_id, $ride['start_time'], $start_latitude, $start_longitude);
        }

        if(empty($ride['summary_polyline']) && !empty($ride['polyline']) ) {

            $ride_points = PolylineEncoder::decodeValue($ride['polyline']);

            echo "Original points: " . count($ride_points) . "<br>";

            $tolerance = 0.0001;

            $simplify_points = simplify($ride_points, $tolerance, true);

            echo "Simplified points: " . count($simplify_points) . "<br>";

            $polyline_encoder = new PolylineEncoder();

            foreach ($simplify_points as $point) {
                $polyline_encoder->addPoint($point['x'], $point['y']);
            }

            $summary_polyline = $polyline_encoder->encodedString();
            //echo $summary_polyline . "<br>";

            update_hh_ride_summary_polyline($ride_id, $summary_polyline);
        }
    }

    echo "<h1>" . $ride['name'] . "</h1>";

    echo round($ride['distance'] / 1000, 1) . "km, ";

    echo round($ride['elevation_gain'], 1) . "m elev. gain, \n";

    echo count(explode(',', $ride['tiles'])) . " tiles" . "<br>";

    echo "<script>var tiles=[\"" . join('","', explode(',', $ride['tiles']))  . "\"];</script>"."\n";

    $polyline = $ride['polyline'];
    echo '<div class="map" id="map-'. $ride['id'] . '" data-polyline="'. base64_encode($polyline) . '"></div>' . "\n";

//    echo "<script>var elevation_geojson=" . $ride['elevation'] . ";</script>"."\n";
//    echo '<div id="altitude_chart"></div>'."\n";

//    echo '<div id="ride_activity_chart"></div>'."\n";
}
else {
    echo "Ride not found: $ride_id <br/>\n";
}


echo '<script src="hh_route.js"></script>'."\n";
?>

</body>
</html>


