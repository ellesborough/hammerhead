<?php

require_once 'PolylineEncoder.php';

class GeoUtils {

    /**
    *    @param string $route
    *    @param array $activity
    *    @return array
    */

    public static function matchActivityToRoute(string $route, array $activity):array {

        $route_geojson = json_decode($route, true);
        $route_points = $route_geojson['coordinates']; // assumes simple {"type": "LineString", "coordinates": [...
        $route_length = static::length($route_points);
        
        $activity_points = PolylineEncoder::decodeValue($activity['map']['polyline']);
        $activity_points = array_map(function ($p) { return [$p['y'], $p['x']];}, $activity_points);        
        $activity_length = static::length($activity_points);

        $length_ratio = $activity_length / $route_length;

        $comparison_points = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];
        $error_distances = [];

        foreach ($comparison_points as $ratio) {

            $route_ll = static::interpolateOnLine($route_points, $ratio);
            $activity_ll = static::interpolateOnLine($activity_points, $ratio);

            $error =  static::distance($route_ll[1], $route_ll[0], $activity_ll[1], $activity_ll[0]);

            $error_distances[$ratio*100 . '%'] = $error;            
        };

        $average_error = array_reduce($error_distances, function ($acc, $v) { return $acc + $v;}, 0) / count($error_distances);

        $length_threshold = 0.05;
        $error_threshold = 20.0;
        
        return [
            'route_length'    => $route_length,
            'activity_length' => $activity_length,
            'length_ratio'    => $length_ratio,
            'error_distances' => $error_distances,
            'average_error'   => $average_error,
            'match'           => ($length_ratio <= 1+$length_threshold && $length_ratio >= 1-$length_threshold) 
                                        && ($average_error <= $error_threshold)
        ];        
    }

    /**
    *    @param array $point_a
    *    @param array $point_b
    *    @param float $ratio
    *    @return array
    */

    // returns the point located on a segment at the specified ratio of the segment length.

    public static function interpolateOnPointSegment (array $point_a, array $point_b, float $ratio) {
        return [
            ($point_a[0] * (1 - $ratio)) + ($point_b[0] * $ratio),
            ($point_a[1] * (1 - $ratio)) + ($point_b[1] * $ratio)
        ];
    }

    /**
    *    @param array $points
    *    @param float $ratio
    *    @return array
    */

    // returns the coordinate of the point [longitude, latitude], located on a line at the specified ratio of the line length.
    public static function interpolateOnLine (array $points, float $ratio):array {

        $n = count($points);

        if($n < 2) return NULL;

        // ensure the ratio is between 0 and 1;
        $ratio = max(min($ratio, 1), 0);

        if ($ratio === 0) {
            return $points[0];
        }
        elseif ($ratio == 1) {
            return $points[n-1];
        }

        $line_length = static::length($points);
        $ratio_dist = $line_length * $ratio;

        // follow the line segments [ab], adding lengths, until we find the segment where the point should lie on
		$cumulative_distance_to_a = 0;
        $cumulative_distance_to_b = 0;

        for ($i = 0; $cumulative_distance_to_b < $ratio_dist; $i++) {

			$point_a = $points[$i];
            $point_b = $points[$i+1];

			$cumulative_distance_to_a = $cumulative_distance_to_b;
			$cumulative_distance_to_b += static::distance($point_a[1], $point_a[0], $point_b[1], $point_b[0]);
		}

        if (!isset($point_a) && !isset($point_b)) { // Happens when line has no length

			$point_a = $points[0];
            $point_b = $points[1];
            $i = 1;
		}

        // compute the ratio relative to the segment [ab]
		$segment_ratio = (($cumulative_distance_to_b - $cumulative_distance_to_a) !== 0) ?
          (($ratio_dist - $cumulative_distance_to_a) / ($cumulative_distance_to_b - $cumulative_distance_to_a)) : 0;

		$interpolated_point = static::interpolateOnPointSegment($point_a, $point_b, $segment_ratio);

        return $interpolated_point;
    }


    /**
	 * @param array $points
	 * @return float
	 */

    // iterate over the points [longitude, latitude], acumulating the distance between each pair
	public static function length(array $points): float {

        $length = 0.0;

        $n = count($points);

        if($n < 2) return $length;

        for ($i = 1; $i < $n; $i++) {

            $length += static::distance($points[$i-1][1], $points[$i-1][0], $points[$i][1], $points[$i][0]);
        }
        return $length;
    }

    /**
     * @var int
     */

    protected static $earth_radius = 6371000;

    /**
     * @param float $fromLatitude
     * @param float $fromLongitude
     * @param float $toLatitude
     * @param float $toLongitude
     *
     * @return float
     */

    // based on Haversine - from  https://github.com/orafaelfragoso/php-haversine-formula/blob/master/src/Haversini.php

    public static function distance(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float {

        $latitudeRadian = deg2rad($toLatitude - $fromLatitude);
        $longitudeRadian = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latitudeRadian / 2) * sin($latitudeRadian / 2)
                + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeRadian / 2) * sin($longitudeRadian / 2);

        return static::$earth_radius * (2 * asin(sqrt($a)));
    }
}