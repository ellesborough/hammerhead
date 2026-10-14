<?php

function getHHUserProfile($client, $hh_user_id, $hh_access_token) {

    $response = $client->request('GET', "/v1/users/$hh_user_id/profile", [
        'headers' => [
            'Authorization' => 'Bearer '. $hh_access_token
        ]
    ]);
    
    if($response->getStatusCode() == 200) {

        $user_profile = json_decode($response->getBody(), true);

        $_SESSION["HH_USER_PROFILE"] = json_encode($user_profile);

        return $user_profile;        
    }
    else {
        return null;
    }
}