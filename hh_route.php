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

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

require 'hh_session_check.php';

$route_id = $_GET["route_id"];

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

function update_hh_route_polyline($route_id, $route_polyline) {

    global $db;
    global $hh_user_id;

    $sql = "UPDATE hh_routes SET route_polyline=:polyline WHERE id=:id AND user_id=:user_id";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if(!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }

    $stmt->bindValue(':polyline', $route_polyline);
    $stmt->bindValue(':id', $route_id);
    $stmt->bindValue(':user_id', $hh_user_id);

    if($stmt->execute())  {
      echo "Updated route polyline <br/>";
     } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}

function update_hh_route_elevation($route_id, $elevation_geojson) {

    global $db;
    global $hh_user_id;

    $sql = "UPDATE hh_routes SET elevation=:elevation WHERE id=:id AND user_id=:user_id";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if(!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }
    $stmt->bindValue(':elevation', $elevation_geojson);
    $stmt->bindValue(':id', $route_id);
    $stmt->bindValue(':user_id', $hh_user_id);

    if($stmt->execute())  {
      echo "Updated route elevation <br/>";
     } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}

function update_hh_route_tiles($route_id, $tiles) {

    global $db;
    global $hh_user_id;

    $sql = "UPDATE hh_routes SET tiles=:elevation WHERE id=:id AND user_id=:user_id";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if(!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }
    $stmt->bindValue(':elevation', $tiles);
    $stmt->bindValue(':id', $route_id);
    $stmt->bindValue(':user_id', $hh_user_id);

    if($stmt->execute())  {
      echo "Updated route tiles <br/>";
     } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}

$stmt = $db->prepare("SELECT * FROM hh_routes WHERE id = :id AND user_id = :user_id");
if (!$stmt) {
    die("Can't prepare statement: " . $db->errorInfo());
}
$stmt->bindValue(':id', $route_id);
$stmt->bindValue(':user_id', $hh_user_id);

$result = $stmt->execute();
if($result) {

    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!empty($route)) {

        if (empty($route['route_polyline'] )) {
            // fetch the full route details

            $response = $client->request('GET', "/v1/users/$hh_user_id/routes/$route_id", [
                'headers' => [
                        'Authorization' => 'Bearer '. $hh_access_token
                ]
            ]);

            if($response->getStatusCode() == 200) {

                $route_details = json_decode($response->getBody(), true);

                $route['route_polyline'] = $route_details['routePolyline'];

                update_hh_route_polyline($route_id, $route['route_polyline']);
            }
        }

        if (empty($route['elevation']) && !empty($route['route_polyline'] )) {
            // fetch the route elevation details

            $response = $client->request('POST', "/v1/routes/elevation", [
                'json' => [
                    'polyline' => $route['route_polyline']
                ],
                'headers' => [
                        'Authorization' => 'Bearer '. $hh_access_token
                ]
            ]);

            if($response->getStatusCode() == 200) {

                $elevation_details = json_decode($response->getBody(), true);

                $resampled_polyline_points = PolylineEncoder::decodeValue($elevation_details['resampledPolyline']);
                $resampled_polyline_points_cnt = count($resampled_polyline_points);
                //echo "Resampled polyline points: $resampled_polyline_points_cnt<br>\n";

                $elevation_points_cnt = count($elevation_details['elevations']);
                //echo "Elevation points: $elevation_points_cnt<br>\n";

                if ($resampled_polyline_points_cnt == $elevation_points_cnt) {

                    $tolerance = 0.00005;

                    $tmp_points = array();

                    for($i = 0; $i < $resampled_polyline_points_cnt; $i++) {

                        $tmp_points[] = array('x' => $resampled_polyline_points[$i]['x'], 'y' => $resampled_polyline_points[$i]['y'], 'z' => $elevation_details['elevations'][$i] );
                    }

                    $simplify_points = simplify($tmp_points, $tolerance, true);

                    echo "Simplified points: " . count($simplify_points) . "<br>\n";

                    $simplify_line = array();
                    foreach ($simplify_points as $point) {
                        $simplify_line[] = array($point['y'], $point['x'], $point['z']);
                    }

                    $geojson = json_encode(array('type' => 'LineString', 'coordinates' => $simplify_line));

                    $route['elevation'] = $geojson;

                    update_hh_route_elevation($route_id, $geojson);
                }
                else {
                    echo "Elevation points count doesn't match resampledPolyline length<br>\n";
                }
            }
        }

        if(empty($route['tiles']) && !empty($route['route_polyline'])) {

            $route_points = PolylineEncoder::decodeValue($route['route_polyline']);
            $tiles = [];
            $zoom = 14;
            foreach ($route_points as $rp) {

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

            $route['tiles'] = join(',', array_keys($tiles));

            update_hh_route_tiles($route_id, $route['tiles']);
        }

        echo "<h1>" . $route['name'] . "</h1>";

        echo round($route['distance'] / 1000, 1) . "km, ";

        echo round($route['elevation_gain'], 1) . "m elev. gain, \n";

        echo count(explode(',', $route['tiles'])) . " tiles" . "<br>";

        echo "<script>var tiles=[\"" . join('","', explode(',', $route['tiles']))  . "\"];</script>"."\n";

        $polyline = $route['route_polyline'] ?? $route['summary_polyline'];
        echo '<div class="map" id="map-'. $route['id'] . '" data-polyline="'. base64_encode($polyline) . '"></div>' . "\n";

        echo "<script>var elevation_geojson=" . $route['elevation'] . ";</script>"."\n";

        echo '<div id="altitude_chart"></div>'."\n";

        echo '<div id="route_activity_chart"></div>'."\n";
    }
    else {
        echo "Route not found: $route_id <br/>\n";
    }
}
else {
    printf("Error message: %s\n", $db->errorInfo());
}

echo '<script src="hh_route.js"></script>'."\n";
?>

</body>
</html>


