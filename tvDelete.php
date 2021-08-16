<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$tools->curl_timeout = 60;

$data_raw = $tools->callPlex("/library/sections/2/search?type=4", 'local');
$episodes = $data_raw['MediaContainer']['Metadata'];

foreach ($episodes as $ep) {
    $show_title = $ep['grandparentTitle'];
    if (in_array($show_title, $tools->config['shows_to_skip'])) {
        continue;
    }
    $episode_name = "{$show_title} - S{$ep['parentIndex']}E{$ep['index']}";
    if (empty($ep['viewCount']) && in_array($show_title, $tools->config['shows_watching'])) {
        // $tools->logToFile("Still need to watch {$episode_name}");
        continue;
    }
    foreach ($ep['Media'] as $filename_info) {
        $filename = $filename_info['Part'][0]['file'];
        if (!strpos($filename, 'Other Videos')) {
            // $tools->logToFile("Not in archive yet: {$episode_name}");
            continue;
        }
        $success = unlink($filename);

        if ($success) {
            $tools->logToFile("Removed {$filename}");
        } else {
            $tools->postToSlack("Failed removing {$filename}");
        }
    }
}
