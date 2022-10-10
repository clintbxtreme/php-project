<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$tools->curl_timeout = 60;

$data_raw = $tools->callPlex("/playlists/76044/items", 'local');
$episodes = $data_raw['MediaContainer']['Metadata'];

foreach ($episodes as $ep) {
    $show_title = $ep['grandparentTitle'];
    $episode_name = "{$show_title} - S{$ep['parentIndex']}E{$ep['index']}";
    foreach ($ep['Media'] as $filename_info) {
        $filename = $filename_info['Part'][0]['file'];
        if (!strpos($filename, 'Other Videos')) {
            // $tools->logToFile("Not in archive yet: {$episode_name}");
            continue 2;
        }
    }
    $tools->callPlex($ep['key'], 'local', 'DELETE');
    if ($tools->curl_error || $tools->curl_error_number || $tools->curl_http_code != 200) {
        $tools->sendError("Failed deleting {$episode_name}");
    } else {
        $tools->logToFile("Deleted {$episode_name}");
    }
}
