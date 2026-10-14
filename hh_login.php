<?php
include 'vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

require 'hh_get_profile.inc.php';

use GuzzleHttp\Client;

function jwtExtractSub($jwt) {

    $parts = explode('.', $jwt);  // Header.Payload.Signature

    $payload = json_decode(base64_decode($parts[1]), true);

    return $payload['sub'];
}

echo "<html><head></head><body>";

$client = new Client([
    'base_uri' => $_ENV['HH_BASE_URL'],
]);

$response = $client->request('POST', '/v1/auth/token', [
    'form_params' => [
        'grant_type' => 'password',
        'username' => $_ENV['HH_USERNAME'],
        'password' => $_ENV['HH_PASSWORD'],
    ]
]);

if($response->getStatusCode() == 200) {

    $resp = json_decode($response->getBody(), true);

    $hh_access_token =  $resp['access_token'];

    $hh_user_id = jwtExtractSub($hh_access_token);

    $hh_token_expires = intval($resp['expires_in']);
    $hh_refresh_token = $resp['refresh_token'];

    $_SESSION["HH_ACCESS_TOKEN"] = $hh_access_token;
    $_SESSION["HH_TOKEN_EXPIRES"] = time() + $hh_token_expires;
    $_SESSION["HH_USER_ID"] = $hh_user_id;

    setcookie("HH_REFRESH_TOKEN", $hh_refresh_token, strtotime('+90 days'));
    
    echo "Signed In<br/>";

    $user_profile = getHHUserProfile($client, $hh_user_id, $hh_access_token);
    if(!empty($user_profile)) {

        $first_name = $user_profile['firstName'] ?? 'Unknown';
        echo "Hello $first_name <br/>";
    }                

    $redirect_url = $_GET['redirect'] ?? '/hammerhead/hh_rides.php';
    echo '<script>setTimeout("window.location=\"' . $redirect_url . '\"", 2000);</script>';

    
}
else {
    echo "Login failure<br/>\n";

    echo $response->getReasonPhrase() . "<br/>\n";
}

echo "</body></html>";



