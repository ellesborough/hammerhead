<?php

include 'vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ClientException;

function fetchWeather($db, $ride_id, $hh_user_id, $time, $latitude, $longitude, $dry_run=FALSE) {

    echo "findWeather:" . $ride_id . " " . $hh_user_id  . " " .  $start_time . "\n";

    $guzzle = new Client([
        'base_uri' => $_ENV['OPEN_METEO_URL'],
    ]);

    $weather_update_query = "UPDATE hh_rides SET weather=:weather_json WHERE id=:id AND user_id=:user_id";
    $stmt = $db->prepare($weather_update_query);

    $stmt->bindValue(':id', $ride_id);
    $stmt->bindValue(':user_id', $hh_user_id);

    $t = strtotime($time) + (30*60);  // add 30 minutes to round 'hour'
    $date = date('Y-m-d', $t);
    $date_time = date('Y-m-d\TH:\0\0', $t);  // truncate the minutes off
    $hour = intval(date('H', $t));

    echo $date . " " . $date_time . " " . $hour . "\n";

    try {
        $response = $guzzle->request('GET', 'v1/archive', [
            'query' => [
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'start_date'=> $date,
                'end_date'  => $date,
                'hourly'    => $_ENV['OPEN_METEO_PARAMS'],
            ]
        ]);

        if($response->getStatusCode() == 200) {

            $json = json_decode($response->getBody(), true);

            if($json['hourly']['time'][$hour] == $date_time) {

                $weather_json = [
                    'source'         => "OpenMeteo",
//                    'time'           => $json['hourly']['time'][$hour],
//                    'latitude'       => $json['latitude'],
//                    'longitude'      => $json['longitude'],                    
                    'temp'           => $json['hourly']['temperature_2m'][$hour],
                    'feels_like'     => $json['hourly']['apparent_temperature'][$hour],
                    'wind_speed'     => $json['hourly']['wind_speed_10m'][$hour],
                    'wind_direction' => $json['hourly']['wind_direction_10m'][$hour],
                    'wind_gust'      => $json['hourly']['wind_gusts_10m'][$hour],
                    'weather_code'   => $json['hourly']['weather_code'][$hour],
                ];

                if(!$dry_run) {
                    $stmt->bindValue(':weather_json', json_encode($weather_json));
                    $result = $stmt->execute();
                }
                else {
                    echo json_encode($weather_json)  . "\n";
                }
            }
        }
        else {
            echo $response->getStatusCode() . ": " . $response->getBody()  . "\n";
        }
    } catch (ClientException $e) {

        $error_response = $e->getResponse();

        $error_status_code = $error_response->getStatusCode();
        $error_data = json_decode($error_response->getBody(), true);

        echo "http error: " . $error_status_code . ": " . $error_response->getBody() . "\n";
    }
}

if ( php_sapi_name() === 'cli' ) {

    $db;

    $servername = $_ENV['MYSQL_SERVER_NAME'];
    $username = $_ENV['MYSQL_USERNAME'];
    $password = $_ENV['MYSQL_PASSWORD'];
    $dbname = $_ENV['MYSQL_DATABASE'];

    $db = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);

    $fixed_ride = FALSE;

    if($fixed_ride) {

        $ride_id = "90463.activity.7489f7db-a2c4-41cb-990f-fbfeebcc54c5";
        $hh_user_id = "90463";
        $start_time = "2026-10-13 13:51:01";
        $mid_time = "2026-10-13 15:18:01";
        $end_time = "2026-10-13 16:45:01";

        $start_latitude  = 50.934187;
        $start_longitude = -1.133360;

        $mid_latitude  = 51.002022;
        $mid_longitude = -0.886704;

        fetchWeather($db, $ride_id, $hh_user_id, $start_time, $start_latitude, $start_longitude, TRUE);
        fetchWeather($db, $ride_id, $hh_user_id, $mid_time, $mid_latitude, $mid_longitude, TRUE);
        fetchWeather($db, $ride_id, $hh_user_id, $end_time, $start_latitude, $start_longitude, TRUE);
    }
    else {    

        $missing_weather_query = "SELECT id, user_id, start_time FROM hh_rides WHERE weather IS NULL ";
        $result = $db->query($missing_weather_query);

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {

            $start_latitude  = 50.934187;
            $start_longitude = -1.133360;

            fetchWeather($db, $row['id'], $row['user_id'], $row['start_time'], $start_latitude, $start_longitude, FALSE);
        }
    }
}


?>