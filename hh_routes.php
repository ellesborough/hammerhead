<html>
  <head>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="route.css">

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://unpkg.com/polyline-encoded@0.0.9/Polyline.encoded.js"></script>
    <script src="https://unpkg.com/apexcharts@3.54.0/dist/apexcharts.js"></script>
    <script src="https://unpkg.com/leaflet-geometryutil@0.10.2/src/leaflet.geometryutil.js"></script>
    <script src="https://unpkg.com/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.js"></script>

    <link rel="stylesheet" href="https://unpkg.com/tablesaw@3.1.2/dist/tablesaw.css">

    <script src="https://unpkg.com/tablesaw@3.1.2/dist/tablesaw.js"></script>
    <script src="https://unpkg.com/tablesaw@3.1.2/dist/tablesaw-init.js"></script>

  </head>
<body>

<?php
include 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

require 'hh_session_check.php';

//print_r ($_SESSION);

$hh_user_id = $_SESSION['HH_USER_ID'];
$hh_access_token  = $_SESSION['HH_ACCESS_TOKEN'];


$servername = $_ENV['MYSQL_SERVER_NAME'];
$username = $_ENV['MYSQL_USERNAME'];
$password = $_ENV['MYSQL_PASSWORD'];
$dbname = $_ENV['MYSQL_DATABASE'];

$CONVERT_M_TO_MILES = 1609.344;

// Create connection
try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
} catch (PDOException $e) {
    die('Error in connection: ' . $e->getMessage());
}

function insert_hh_route($route) {

    global $db;
    global $hh_user_id;

    $sql = "INSERT INTO hh_routes (id, user_id, name, distance, elevation_gain, created, updated, summary_polyline)"
             . " VALUES (:id, :user_id, :name, :distance, :elevation_gain, :created, :updated, :summary_polyline)";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }

    $created_at = rtrim($route['createdAt'],'Z');
    $updated_at = rtrim($route['updatedAt'],'Z');

    $stmt->bindValue(':id',               $route['id']);
    $stmt->bindValue(':user_id',          $hh_user_id);
    $stmt->bindValue(':name',             $route['name']);
    $stmt->bindValue(':distance',         $route['distance']);
    $stmt->bindValue(':elevation_gain',   $route['gain']);
    $stmt->bindValue(':created',          $created_at);
    $stmt->bindValue(':updated',          $updated_at);
    $stmt->bindValue(':summary_polyline', $route['summaryPolyline']);

    if($stmt->execute())  {
      echo "New record created successfully. ID: {$route['id']}<br/>";
    } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}

function update_hh_route($route) {

    global $db;
    global $hh_user_id;

    $sql = <<<EOT
UPDATE hh_routes
SET
    name=:new_name,
    distance=:distance,
    elevation_gain=:elevation_gain,
    created=:created,
    updated=:updated,
    summary_polyline=:summary_polyline,
    route_polyline=NULL,
    elevation=NULL,
    tiles=NULL
WHERE id=:id AND user_id=:user_id
EOT;
    #echo $sql;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }

    $created_at = rtrim($route['createdAt'],'Z');
    $updated_at = rtrim($route['updatedAt'],'Z');

    $stmt->bindValue(':id',               $route['id']);
    $stmt->bindValue(':user_id',          $hh_user_id);
    $stmt->bindValue(':new_name',         $route['name']);
    $stmt->bindValue(':distance',         $route['distance']);
    $stmt->bindValue(':elevation_gain',   $route['gain']);
    $stmt->bindValue(':created',          $created_at);
    $stmt->bindValue(':updated',          $updated_at);
    $stmt->bindValue(':summary_polyline', $route['summaryPolyline']);

    if($stmt->execute())  {
        echo "Route updated successfully. ID: {$route['id']}<br/>";
    } else {
        echo "Error: " . $sql . "<br>" . print_r($stmt->errorInfo());
    }
}


function update_hh_route_name($route_id, $new_name, $updated) {

    global $db;
    global $hh_user_id;

    $sql = "UPDATE hh_routes SET name=:new_name, updated=:updated WHERE id=:id AND user_id=:user_id";
    #echo $sql;

    $stmt = $db->prepare($sql);
    if(!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }

    $updated_at = rtrim($updated,'Z');

    $stmt->bindValue(':id',       $route_id);
    $stmt->bindValue(':user_id',  $hh_user_id);
    $stmt->bindValue(':updated',  $updated_at);
    $stmt->bindValue(':new_name', $new_name);

    if($stmt->execute())  {
      echo "Updated route name <br/>";
     } else {
      echo "Error: " . $sql . "<br>" . $db->errorInfo();
    }
}


echo '<header>';
  include 'header.php';
echo '</header>';


// Get all HH routes in the DB
$sql = "SELECT * FROM hh_routes WHERE user_id = :user_id";

$stmt = $db->prepare($sql);
if (!$stmt) {
    die("Can't prepare statement: " . $db->errorInfo());
}

$stmt->bindValue(':user_id', $hh_user_id);

$result = $stmt->execute();

$hh_routes = [];
if($result) {
    while ($route = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hh_routes[$route['id']] = $route;
    }
}

$client = new Client([
    'base_uri' => $_ENV['HH_BASE_URL'],
]);

$response = $client->request('GET', "/v1/users/$hh_user_id/routes", [
    'query' => [
        'perPage'   => '100',
        'page'      => '1',
        'order_by'  => 'NEWEST',
        'ascending' => 'true',
        'exclude'   => 'archive'
    ],
    'headers' => [
        'Authorization' => 'Bearer '. $hh_access_token
    ]
]);

echo '<H1>' . 'All Hammerhead Routes' . '</H1>';

if($response->getStatusCode() == 200) {

    echo '<table border="1" data-tablesaw-sortable >';
        echo '<thead><tr>';
        echo '<th data-tablesaw-sortable-col>' . "Name";
        echo '<th id="date-sort" data-tablesaw-sortable-col>' . "Date";
        echo '<th id="dist-sort" data-tablesaw-sortable-col>' . "Distance";
        echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Elevation Gain";
        echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Climb Rate";
        echo '<th id="tile-sort" data-tablesaw-sortable-col >' . "Tiles";
        echo '<th>' . "Map";
        echo '<th>';
        echo "</tr></thead>";

    $routes = json_decode($response->getBody(), true);

    echo 'Loaded ' . count($routes['data']) . ' routes of ' . $routes['totalItems'] . ' (Page ' . $routes['currentPage'] . ' of ' . $routes['totalPages'] . ')' . "<BR>";

    foreach ($routes['data'] as $idx => $route) {

        if(empty($hh_routes[$route['id']])) {

            insert_hh_route($route);
        }
        else {

            $db_route = $hh_routes[$route['id']];

            $api_date_epoch = strtotime($route['updatedAt']);
            $db_date_epoch = date_create_immutable($db_route['updated'] . "UTC" )->setTimezone(new DateTimeZone("Europe/London"))->format('U');

            if($api_date_epoch != $db_date_epoch ) {

                echo "Local copy of " . $db_route['name'] . " is out of date: "
                    . $route['updatedAt']
                    //. " " . $api_date_epoch
                    . " vs "
                    .  $hh_routes[$route['id']]['updated']
                    //. " " . $db_date_epoch
                    . "<br>";

                if(abs($route['distance'] - $db_route['distance']) > 1.0) {
                    echo "Route distance is different: " . round($route['distance'] - $db_route['distance'])  ."m" . "<br>";
                    update_hh_route($route);  // will need a full update
                }
                else if ($route['name'] != $db_route['name']) {
                    echo "Route name is different" . "<br>";
                    update_hh_route_name($db_route['id'], $route['name'], $route['updatedAt'] );  // just update the name field
                }
            }
        }


        $created_at = date('j M Y', strtotime($route['createdAt']));
        $updated_at = date('j M Y', strtotime($route['updatedAt']));

        echo '<tr>';

        echo '<td>' . $route['name'] .  '</td>';

        echo '<td>' . $created_at
                . (($created_at != $updated_at) ? '<br>(updated: ' . $updated_at .  ')' : '')
                .  '</td>';

        echo '<td>'
                . round($route['distance'] / 1000, 1) . ' km'
                . "<br>" . "(" . round($route['distance'] / $CONVERT_M_TO_MILES, 1) . ' miles)'
                . '</td>';

        echo '<td>' . round($route['gain'], 0) . ' m' . '</td>';

        echo '<td>' . number_format(round($route['gain'] / ($route['distance']/1000), 1), 1) . ' m/km' . '</td>';

        if(!empty($hh_routes[$route['id']])) {

            echo '<td>' . (!empty($hh_routes[$route['id']]['tiles']) ? count(explode(',', $hh_routes[$route['id']]['tiles'])) : "") . '</td>';
        }
        else {
            echo '<td></td>';
        }

        echo '<td><div class="map-thumbnail" id="map-'. $route['id'] . '" data-polyline="'. base64_encode($route['summaryPolyline']) . '"></div></td>' . "\n";

        echo '<td>'
            . '<a href="hh_route.php?route_id=' . $route['id'] . '">View</a><br><br>'
            . '<a href="https://dashboard.hammerhead.io/routes/' . $route['id'] . '" target=_blank><img src="images/hh_logo.png"  width=40></a>'
            . '</td>';

        echo "</tr>\n";
    }

    echo "</table>";
}
?>

<script src="hh_routes.js"></script>

</body>
</html>
