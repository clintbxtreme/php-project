<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();

$types = [
    'TV'    => '2',
    'movie' => '1',
];

$allowed_ratings = [
    'TV'    => [
        'TV-G',
        'TV-Y',
        'TV-Y7',
        'TV-PG',
        'TV-14',
        'TV-MA',
        'None',
    ],
    'movie' => [
        'G',
        'PG',
        'PG-13',
        'R',
        'None',
    ],
];

$movie_rating_changes = [
    'Approved' => 'G',
    '12'       => 'PG',
    'TV-G'     => 'G',
    'TV-Y'     => 'G',
    'TV-Y7'    => 'G',
    'TV-PG'    => 'PG',
    'TV-14'    => 'PG-13',
    'TV-MA'    => 'R',
    'NC-17'    => 'R',
    'MA-17'    => 'R',
];

foreach ($types as $type => $section) {
    foreach (['remote', 'local'] as $server) {
        $ratings = $tools->callPlex("/library/sections/{$section}/contentRating", $server);
        foreach ($ratings['MediaContainer']['Directory'] as $rating_data) {
            $rating = $rating_data['key'];
            if ($type == 'movie' && $rating == 'None') {
                $movies = $tools->callPlex("/library/sections/1/all?contentRating={$rating}", $server);
                foreach ($movies['MediaContainer']['Metadata'] as $m) {
                    $status = $tools->callPlex("/library/metadata/{$m['ratingKey']}/refresh", $server, 'PUT');
                    if ($tools->curl_http_code != 200) {
                        $tools->sendError("Failed refreshing {$m['title']} ({$m['year']}) on {$server}");
                    }
                }
            }
            if (in_array($rating, $allowed_ratings[$type])) {
                continue;
            }
            $new_rating = $movie_rating_changes[$rating] ?? null;
            if (!$new_rating || $type != 'movie') {
                $tools->sendError("Invalid {$type} rating key of {$rating} found on {$server}");
                continue;
            }
            $movies = $tools->callPlex("/library/sections/1/all?contentRating={$rating}", $server);
            if (!$movies['MediaContainer']['size']) {
                $tools->logToFile("Nothing for {$type} rating key of {$rating} found on {$server}");
                continue;
            }
            foreach ($movies['MediaContainer']['Metadata'] as $m) {
                $params = [
                    'type'                 => 1,
                    'id'                   => $m['ratingKey'],
                    'contentRating.value'  => $new_rating,
                    'contentRating.locked' => 1,
                ];
                $params_string = http_build_query($params);
                $status = $tools->callPlex("/library/sections/1/all?{$params_string}", $server, "PUT");
                $msg_base = "rating for {$m['title']} ({$m['year']}) from {$rating} to {$new_rating} on {$server}";
                if ($tools->curl_http_code == 200) {
                    $tools->logToFile("Changed {$msg_base}");
                } else {
                    $msg = "Failed changing {$msg_base}";
                    $tools->sendError($msg);
                }
            }
        }
    }
}
