<?php

//$athlete = json_decode($_SESSION["STRAVA_ATHLETE"], true);
//echo '<a href="athlete.php">';
//    echo '<img src="' . $athlete['profile_medium'] . '" />';
//echo '</a>';
//echo ($athlete['firstname']??"") . " " . ($athlete['lastname']??"") . " ";

$user = json_decode($_SESSION["HH_USER_PROFILE"], true);
echo ($user['firstName'] ?? "") . " " . ($user['lastName'] ?? "") . " ";


//echo '<a href="calendar.php">Calendar</a> ';
echo '<a href="hh_rides.php">Rides</a> ';
echo '<a href="hh_routes.php">Routes</a>' . '<br/>';
