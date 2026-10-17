<html>
  <head>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="route.css">

    <link rel="stylesheet" href="css/weather-icons.min.css">
    <link rel="stylesheet" href="css/weather-icons-wind.min.css">

    <link rel="stylesheet" href="css/metoffice_temp_colors.css">

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
$CONVERT_MPS_TO_MPH = 2.237;
$CONVERT_MPS_TO_KPH = 3.6;
$CONVERT_KPH_TO_MPH = $CONVERT_M_TO_MILES/1000;

// <meter> parameters:  MIN ---- LOW --- HIGH --- MAX
// creates 3 coloured 'bands' which can be green, orange, red
// Param OPTIMUM points to one of the bands, which will be coloured Green
// Either side of that will be Orange.
// A Red band will occur at the extreme if the Green band isn't in the middle

$METER_EFFICIENCY_MIN  =  0;
$METER_EFFICIENCY_LOW  = 0.8;  // Red 0 - 0.8
$METER_EFFICIENCY_HIGH = 0.9;  // Orange 0.8 - 0.9
$METER_EFFICIENCY_OPT  = 1;  // Green 0.9 - 1
$METER_EFFICIENCY_MAX  = 1;
$METER_EFFICIENCY_PARAMS = "min='$METER_EFFICIENCY_MIN' low='$METER_EFFICIENCY_LOW' high='$METER_EFFICIENCY_HIGH' optimum='$METER_EFFICIENCY_OPT' max=$METER_EFFICIENCY_MAX";

$METER_AV_SPEED_MIN  =  0;
$METER_AV_SPEED_LOW  = 12;  // Red 0 - 12
$METER_AV_SPEED_HIGH = 20;  // Orange  12 - 20
$METER_AV_SPEED_OPT  = 21;  // Green 20+
$METER_AV_SPEED_MAX  = 25;
$METER_AV_SPEED_PARAMS = "min='$METER_AV_SPEED_MIN' low='$METER_AV_SPEED_LOW' high='$METER_AV_SPEED_HIGH' optimum='$METER_AV_SPEED_OPT' max=$METER_AV_SPEED_MAX";

$METER_DIST_MIN  =  0;
$METER_DIST_LOW  = 40;  // Red 0-40
$METER_DIST_HIGH = 60;  // Orange 40-60
$METER_DIST_OPT  = 80;  // Green 60-80
$METER_DIST_MAX  = 80;
$METER_DIST_PARAMS = "min='$METER_DIST_MIN' low='$METER_DIST_LOW' high='$METER_DIST_HIGH' optimum='$METER_DIST_OPT' max=$METER_DIST_MAX";

$METER_CLIMB_MIN  = 0;
$METER_CLIMB_OPT  = 10;
$METER_CLIMB_LOW  = 12;  // Green 0 - 12
$METER_CLIMB_HIGH = 14;  // Orange 12 - 14
$METER_CLIMB_MAX  = 18;  // Red 14 - 18
$METER_CLIMB_PARAMS = "min='$METER_CLIMB_MIN' low='$METER_CLIMB_LOW' high='$METER_CLIMB_HIGH' optimum='$METER_CLIMB_OPT' max=$METER_CLIMB_MAX";

$METER_ELEVATION_MIN  = 0;
$METER_ELEVATION_OPT  = 400;
$METER_ELEVATION_LOW  = 500;  // Green 
$METER_ELEVATION_HIGH = 700;  // Orange 
$METER_ELEVATION_MAX  = 1000;  // Red 
$METER_ELEVATION_PARAMS = "min='$METER_ELEVATION_MIN' low='$METER_ELEVATION_LOW' high='$METER_ELEVATION_HIGH' optimum='$METER_ELEVATION_OPT' max=$METER_ELEVATION_MAX";



// Create connection
try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
} catch (PDOException $e) {
    die('Error in connection: ' . $e->getMessage());
}


//polyline, tiles, record_data, climbs, pauses

function insert_hh_ride($ride) {

    global $db;
    global $hh_user_id;

    $sql = "INSERT INTO hh_rides (user_id, id, created, strava_activity_id, name, start_time, elapsed_time, active_time, distance, elevation_gain, average_temperature, average_speed, max_speed)"
             . " VALUES (:user_id, :id, :created, :strava_activity_id, :name, :start_time, :elapsed_time, :active_time, :distance, :elevation_gain, :average_temperature, :average_speed, :max_speed)";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die("Can't prepare statement: " . $db->errorInfo());
    }

    $strava_id = "";
    foreach ($ride['sync']['partners'] as $partner) {
        if($partner['partner'] == 'strava') {
            $strava_id = $partner['externalId'];
            break;
        }
    }

    $distance = null ;
    $elevation_gain = null;
    $average_temperature = null;
    $average_speed = null;
    $max_speed = null;

    foreach ($ride['activityInfo'] as $info) {
        switch($info['key']) {
            case "TYPE_DISTANCE_ID":             $distance            = $info['value']['value']; break;
            case "TYPE_ELEVATION_GAIN_ID":       $elevation_gain      = $info['value']['value']; break;
            case "TYPE_AVERAGE_TEMPERATURE_ID":  $average_temperature = $info['value']['value']; break;
            case "TYPE_AVERAGE_SPEED_ID":        $average_speed       = $info['value']['value']; break;
            case "TYPE_MAX_SPEED_ID":            $max_speed           = $info['value']['value']; break;
        }
    }

    $stmt->bindValue(':user_id',            $hh_user_id);
    $stmt->bindValue(':id',                 $ride['id']);
    $stmt->bindValue(':created',            $ride['createdAt']);
    $stmt->bindValue(':strava_activity_id', $strava_id);
    $stmt->bindValue(':name',               $ride['name']);
    $stmt->bindValue(':start_time',         $ride['duration']['startTime']);

    // used instead of activityInfo fields: "TYPE_ELAPSED_TIME_ID"  "TYPE_RIDE_TIME_ID"
    $stmt->bindValue(':elapsed_time',       $ride['duration']['elapsedTime'] / 1000);  // convert to seconds not milliseconds
    $stmt->bindValue(':active_time',        $ride['activeTime'] / 1000);

    $stmt->bindValue(':distance',            $distance);
    $stmt->bindValue(':elevation_gain',      $elevation_gain);
    $stmt->bindValue(':average_temperature', $average_temperature);
    $stmt->bindValue(':average_speed',       $average_speed);
    $stmt->bindValue(':max_speed',           $max_speed);

    if($stmt->execute())  {
      echo "New record created successfully. ID: {$ride['id']}<br/>";
    } else {
      echo "Error: " . $sql . "<br>" . $mysqli->error;
    }
}

function mph_to_beaufort($mph) {
    if     ($mph <   1) return 0;
    elseif ($mph <=  3) return 1;
    elseif ($mph <=  7) return 2;
    elseif ($mph <= 12) return 3;
    elseif ($mph <= 18) return 4;
    elseif ($mph <= 24) return 5;
    elseif ($mph <= 31) return 6;
    elseif ($mph <= 38) return 7;
    elseif ($mph <= 46) return 8;
    elseif ($mph <= 54) return 9;
    elseif ($mph <= 63) return 10;
    elseif ($mph <= 72) return 11;
    else                return 12;
}

function wind_degrees_to_compass($wind_dir) {

    if     ($wind_dir <=  11.25) return 'N';
    elseif ($wind_dir <=  33.75) return 'NNE';
    elseif ($wind_dir <=  56.25) return 'NE';
    elseif ($wind_dir <=  78.75) return 'ENE';
    elseif ($wind_dir <= 101.25) return 'E';
    elseif ($wind_dir <= 123.75) return 'ESE';
    elseif ($wind_dir <= 146.25) return 'SE';
    elseif ($wind_dir <= 168.75) return 'SSE';
    elseif ($wind_dir <= 191.25) return 'S';
    elseif ($wind_dir <= 213.75) return 'SSW';
    elseif ($wind_dir <= 236.25) return 'SW';
    elseif ($wind_dir <= 258.75) return 'WSW';
    elseif ($wind_dir <= 281.25) return 'W';
    elseif ($wind_dir <= 303.75) return 'WNW';
    elseif ($wind_dir <= 326.25) return 'NW';
    elseif ($wind_dir <= 348.75) return 'NNW';
    else                         return 'N';
}

// https://open-meteo.com/en/docs/historical-weather-api

function openmeteo_weather_code_txt($code) {
    switch($code) {
        case  0: return "Clear sky"; break;
        case  1: return "Mainly clear"; break;
        case  2: return "Partly cloudy"; break;
        case  3: return "Overcast"; break;
        case 45: return "Fog"; break;
        case 48: return "Depositing rime fog"; break;
        case 51: return "Light drizzle"; break;
        case 53: return "Moderate drizzle"; break;
        case 55: return "Dense drizzle"; break;
        case 56: return "Light freezing drizzle"; break;
        case 57: return "Dense freezing drizzle"; break;
        case 61: return "Slight rain"; break;
        case 63: return "Moderate rain"; break;
        case 65: return "Heavy rain"; break;
        case 66: return "Light freezing rain"; break;
        case 67: return "Heavy freezing rain"; break;
        case 71: return "Slight snowfall"; break;
        case 73: return "Moderate snowfall"; break;
        case 75: return "Heavy snowfall"; break;
        case 77: return "Snow grains"; break;
        case 80: return "Slight rain showers"; break;
        case 81: return "Moderate rain showers"; break;
        case 82: return "Violent rain showers"; break;
        case 85: return "Slight snow showers"; break;
        case 86: return "Heavy snow showers"; break;
        case 95: return "Thunderstorm"; break;
        case 96: return "Thunderstorm with slight hail"; break;
        case 97: return "Heavy thunderstorm"; break;
        case 99: return "Thunderstorm with heavy hail"; break;
        default:  return "Code: $code";
    }
}

function openmeteo_weather_code_icon($code) {
    switch($code) {
        case  0: return 'wi-day-sunny'; break;  // "Clear sky"
        case  1: return 'wi-day-cloudy-high'; break;  // "Mainly clear"
        case  2: return 'wi-day-sunny-overcast'; break; // "Partly cloudy"
        case  3: return 'wi-cloudy'; break;  // "Overcast"
        case 45: return 'wi-day-fog'; break; // "Fog"
        case 48: return 'wi-day-fog'; break; // "Depositing rime fog"
        case 51: return 'wi-day-sprinkle'; break; // "Light drizzle"
        case 53: return 'wi-day-sprinkle'; break; // "Moderate drizzle"
        case 55: return 'wi-day-sprinkle'; break; // "Dense drizzle"
        case 56: return 'wi-day-sprinkle'; break; // "Light freezing drizzle"
        case 57: return 'wi-day-sprinkle'; break; // "Dense freezing drizzle"
        case 61: return 'wi-rain-mix'; break; // "Slight rain"
        case 63: return 'wi-rain'; break; // "Moderate rain"
        case 65: return 'wi-rain'; break; // "Heavy rain"
        case 66: return 'wi-rain-mix'; break; // "Light freezing rain"
        case 67: return 'wi-rain'; break; // "Heavy freezing rain"
        case 71: return 'wi-day-snow'; break; // "Slight snowfall"
        case 73: return 'wi-day-snow'; break; // "Moderate snowfall"
        case 75: return 'wi-day-snow'; break; // "Heavy snowfall"
        case 77: return 'wi-day-snow'; break; // "Snow grains"
        case 80: return 'wi-showers'; break; // "Slight rain showers"
        case 81: return 'wi-showers'; break; // "Moderate rain showers"
        case 82: return 'wi-rain'; break; // "Violent rain showers"
        case 85: return 'wi-day-snow'; break; // "Slight snow showers"
        case 86: return 'wi-day-snow'; break; // "Heavy snow showers"
        case 95: return 'wi-day-thunderstorm'; break; // "Thunderstorm"
        case 96: return 'wi-day-thunderstorm'; break; // "Thunderstorm with slight hail"
        case 97: return 'wi-day-thunderstorm'; break; // "Heavy thunderstorm"
        case 99: return 'wi-day-thunderstorm'; break; // "Thunderstorm with heavy hail"
        default:  return "wi-na";
    }
}

echo '<header>';
  include 'header.php';
echo '</header>';

echo '<H1>' . 'All Hammerhead Rides' . '</H1>';

// Get all HH ride IDs in the DB
$sql = "SELECT id FROM hh_rides WHERE user_id = :user_id";

$stmt = $db->prepare($sql);
if (!$stmt) {
    die("Can't prepare statement: " . $db->errorInfo());
}

$stmt->bindValue(':user_id', $hh_user_id);

$result = $stmt->execute();

$hh_rides = [];  // just the IDs
if($result) {
    while ($ride = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hh_rides[$ride['id']] = TRUE;
    }
}

$client = new Client([
    'base_uri' => $_ENV['HH_BASE_URL'],
]);

try {
    $response = $client->request('GET', "/v1/users/$hh_user_id/activities", [
        'query' => [
            'perPage'   => '25',
            'page'      => '1',
            'order_by'  => 'NEWEST',
            'ascending' => 'true',
        ],
        'headers' => [
            'Authorization' => 'Bearer '. $hh_access_token
        ]
    ]);

    if($response->getStatusCode() == 200) {

        $rides = json_decode($response->getBody(), true);

        echo 'Loaded ' . count($rides['data']) . ' rides of ' . $rides['totalItems'] . ' (Page ' . $rides['currentPage'] . ' of ' . $rides['totalPages'] . ')' . "<BR>";

        foreach ($rides['data'] as $idx => $ride) {

            if(!array_key_exists($ride['id'], $hh_rides)) {

                insert_hh_ride($ride);
            }
        }
    }
    else {
        echo "Failed to load latest rides from Hammerhead" . "<br>";
        echo $response->getStatusCode() . ": " . $response->getBody()  . "<br>";
    }

} catch (ClientException $e) {

    $error_response = $e->getResponse();
    $error_status_code = $error_response->getStatusCode();
    echo "http error: " . $error_status_code . ": " . $error_response->getBody() . "<br>";
}

// Now get required details of all HH rides in the DB, don't fetch record_data which is large and not used here
$sql = <<<EOT
SELECT user_id, id, created, strava_activity_id, name, start_time, elapsed_time, active_time, distance,
            elevation_gain, average_temperature, average_speed, max_speed, tiles, weather, polyline, summary_polyline
FROM hh_rides
WHERE user_id = :user_id
ORDER BY start_time DESC
EOT;

$stmt = $db->prepare($sql);
if (!$stmt) {
    die("Can't prepare statement: " . $db->errorInfo());
}

$stmt->bindValue(':user_id', $hh_user_id);

$result = $stmt->execute();
if($result) {

    $rows = [];
    $max_distance = 0;

    while ($ride = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $row = [];

        $row[0] = $ride['name'];

        // Date
        $row[1] = date_create_immutable($ride['start_time'] . "UTC" )->setTimezone(new DateTimeZone("Europe/London"))->format('j M Y, H:i');

        // Times
        $row[2] = sprintf('%2dh%02dm', ($ride['active_time']/ 3600),($ride['active_time']/ 60 % 60));

        // Efficiency
        $paused_time = $ride['elapsed_time'] - $ride['active_time'];
        $row[3] = round(100 * $ride['active_time'] / $ride['elapsed_time']) . "%" . "<br>"
            . "(Paused " . sprintf('%2dm%02ds', ($paused_time / 60 % 60), $paused_time % 60) . ")" . "<br>"
            . "<meter $METER_EFFICIENCY_PARAMS value='" . $ride['active_time'] / $ride['elapsed_time'] . "' ></meter>";

        // Speed
        $row[4] = number_format(round($ride['average_speed'] * $CONVERT_MPS_TO_KPH,1),1) . " km/h" . "<br>"
            . "(" . number_format(round($ride['average_speed'] * $CONVERT_MPS_TO_MPH,1),1) . " mph)" . "<br>"
            . "<meter $METER_AV_SPEED_PARAMS value='" . ($ride['average_speed'] * $CONVERT_MPS_TO_KPH) . "' ></meter>";

        // Distance
        $row[5] = number_format(round($ride['distance'] / 1000, 1),1) . ' km'
            . "<br>" . "(" . number_format(round($ride['distance'] / $CONVERT_M_TO_MILES, 1),1) . ' miles)' . "<br>"
            . "<meter $METER_DIST_PARAMS value='" . $ride['distance'] / 1000 . "' ></meter>";

        // Elevation
        $row[6] = round($ride['elevation_gain'], 0) . ' m' . "<br>"
            . "<br>"
            . "<meter $METER_ELEVATION_PARAMS value='" . $ride['elevation_gain'] . "' ></meter>";

        // Climb Rate
        $row[7] = number_format(round($ride['elevation_gain'] / ($ride['distance']/1000), 1), 1) . ' m/km' . "<br>"
            . "<br>"
            . "<meter $METER_CLIMB_PARAMS value='" . $ride['elevation_gain'] / ($ride['distance']/1000) . "' ></meter>";

        // Weather

        if(!empty($ride['weather'])) {
            $weather = json_decode($ride['weather'], TRUE);
            // {"source":"OpenMeteo","temp":12.2,"feels_like":12.1,"wind_speed":4.5,"wind_direction":275,"wind_gust":12.2,"weather_code":3}

            $weather_icon = openmeteo_weather_code_icon($weather['weather_code']);
            $beaufort = mph_to_beaufort($weather['wind_speed'] / $CONVERT_KPH_TO_MPH);
            $wind_speed_icon_class = 'wi-wind-beaufort-' . $beaufort;
            $wind_dir_icon_class = 'from-' . $weather['wind_direction'] . '-deg';

            $row[8] = ""
                . openmeteo_weather_code_txt($weather['weather_code'])
                . ' <span style="font-size:24px" class="weather-icon-symbol">' . "<i class=\"wi $weather_icon\"></i> " . '</span>'
                . "<br>"
                . '<span class="dailyMaxTemp' . round($weather['temp']) . '" >' . round($weather['temp']) . '&deg;C' . "</span>"
                . "<br>"
                . "Feels like " . round($weather['feels_like']) . '&deg;C' . "<br>"
                . (!empty($ride['average_temperature']) ? "Karoo " . round($ride['average_temperature']) . '&deg;C' : "") // Karoo measured temperature
                . "<br>"
    //            . round($weather['wind_speed']) . ' km/h'
    //            . " ("
    //                . round($weather['wind_speed'] / $CONVERT_KPH_TO_MPH) . " mph"
    //                . ", force " . $beaufort
    //            . ")"
    //            . " " . wind_degrees_to_compass($weather['wind_direction']) . " (" . round($weather['wind_direction']) . '&deg;)'  . "<br>"
                . wind_degrees_to_compass($weather['wind_direction']) . " "
                . '<span class="weather-icon-wind">'
                    . "<i class=\"wi wi-wind $wind_dir_icon_class\"></i> "
                    . " <i class=\"wi $wind_speed_icon_class\"></i> "
                . '</span>'
                ;
        }
        else {
            $row[8] =  !empty($ride['average_temperature']) ? "Karoo " . round($ride['average_temperature']) . '&deg;C' : ""; // Karoo measured temperature
        }

        // Tiles
        $row[9] = (!empty($ride['tiles']) ? count(explode(',', $ride['tiles'])) : "");

        // Map
        if(!empty($ride['polyline']) || !empty($ride['summary_polyline'])) {

            $polyline = $ride['summary_polyline'] ?? $ride['polyline'];

            $row[10] = '<div class="map-thumbnail" id="map-'. $ride['id'] . '" data-polyline="'. base64_encode($polyline) . '"></div>';
        }
        else {
            $row[10] = "";
        }

        // Links
        $row[11] = '<a href="hh_ride.php?ride_id=' . $ride['id'] . '">Details</a><br><br>'
            . '<a href="https://dashboard.hammerhead.io/activities/' . $ride['id'] . '" target=_blank><img src="images/hh_logo.png"  width=40></a><br><br>'
            . ((!empty($ride['strava_activity_id'])) ? ('<a href="https://www.strava.com/activities/' . $ride['strava_activity_id'] . '" target=_blank><img src="images/strava_logo.png"  width=30></a>') : "");

        $rows[] = $row;
    }

    echo '<table border="1" data-tablesaw-sortable >';
    echo '<thead><tr>';
    echo '<th >' . "Name";
    echo '<th id="date-sort" data-tablesaw-sortable-col>' . "Ride Start";
    echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Ride Time";
    echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Efficiency";
    echo '<th id="dist-sort" data-tablesaw-sortable-col>' . "Av. Speed";
    echo '<th id="dist-sort" data-tablesaw-sortable-col>' . "Distance";
    echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Elevation";
    echo '<th data-tablesaw-sortable-col data-tablesaw-sortable-numeric>' . "Climb Rate";
    echo '<th >' . "Weather";
    echo '<th id="tile-sort" data-tablesaw-sortable-col >' . "Tiles";
    echo '<th>' . "Map";
    echo '<th>';  // Links
    echo "</tr></thead>";

    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $c) {
            echo "<td>" . $c . "</td>";
        }
        echo "</tr>" . "\n";
    }


    echo "</table>";
}
?>

<script src="hh_rides.js"></script>

</body>
</html>
