<?php

//print_r($_SESSION);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require 'hh_get_profile.inc.php';

if (!array_key_exists('HH_ACCESS_TOKEN', $_SESSION) && empty($_COOKIE["HH_REFRESH_TOKEN"])) {

    // neither of these exist, so go to authenticate page

    header('Location: '. 'hh_login.php?redirect=' . $_SERVER['REQUEST_URI']);
    http_response_code(302);
    exit();
}


function jwtExtractSub($jwt) {

    $parts = explode('.', $jwt);  // Header.Payload.Signature

    $payload = json_decode(base64_decode($parts[1]), true);

    return $payload['sub'];
}


if(intval($_SESSION["HH_TOKEN_EXPIRES"]) < time() ) { // need to refresh the Hammerhead token

    if (!empty($_COOKIE["HH_REFRESH_TOKEN"])) {

        $client = new Client([
            'base_uri' => $_ENV['HH_BASE_URL'],
        ]);

        try {

            $response = $client->request('POST', '/v1/auth/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $_COOKIE["HH_REFRESH_TOKEN"],
                ]
            ]);

            if($response->getStatusCode() == 200) {

                $resp = json_decode($response->getBody(), true);

                $hh_access_token =  $resp['access_token'];
                $hh_token_expires = intval($resp['expires_in']);
                $hh_refresh_token = $resp['refresh_token'];

                $_SESSION["HH_ACCESS_TOKEN"] = $hh_access_token;
                $_SESSION["HH_TOKEN_EXPIRES"] = time() + $hh_token_expires;

                $hh_user_id = jwtExtractSub($hh_access_token);

                $_SESSION["HH_USER_ID"] = $hh_user_id;

                setcookie("HH_REFRESH_TOKEN", $hh_refresh_token, strtotime('+90 days'));

                getHHUserProfile($client, $hh_user_id, $hh_access_token);

                // TODO in some message header?
                echo "Token Refreshed<br/>\n";
            }
            else {
                header('Location: '. 'hh_login.php?redirect=' . $_SERVER['REQUEST_URI']);
                http_response_code(302);
                exit();
            }
        } catch (RequestException $e) {

            $error_response = $e->getResponse();

            $error_status_code = $error_response->getStatusCode();
            $error_data = json_decode($error_response->getBody(), true);

            error_log("http error: " . $error_status_code . ": " . $error_response->getBody());
            error_log("refreh_token: " . $_COOKIE["HH_REFRESH_TOKEN"]);

            setcookie("HH_REFRESH_TOKEN", "", time() - 3600);  // clear cookie

            header('Location: '. 'hh_login.php?redirect=' . $_SERVER['REQUEST_URI']);
            http_response_code(302);
        }
    }
    else {
        header('Location: '. 'hh_login.php?redirect=' . $_SERVER['REQUEST_URI']);
        http_response_code(302);
        exit();
    }
}