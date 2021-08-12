<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();

$removed = false;
$movies_data = $tools->callPlex("/library/sections/1/all", 'drobo');
if (!$movies_data) {
    $tools->sendError("failed getting Plex movies list in manageHighQualityVideos", true);
}
foreach ($movies_data['MediaContainer']['Metadata'] as $movie) {
    if (isset($movie['viewCount']) && $movie['viewCount']) {
        foreach ($movie['Media'] as $media) {
            $title = $movie['title'];
            foreach ($media['Part'] as $part) {
                $filename = $part['file'];
                if (strpos($filename, "Videos-high")) {
                    $status = unlink($filename);
                    if ($status) {
                        $tools->logToFile("Successfully removed {$title}");
                    } else {
                        $tools->postToSlack("Failed removing {$title}");
                    }
                    $removed = true;
                }
            }
        }
    }
}

if ($removed) {
    $tools->postToUrl($tools->config['video_sync_url']);
}