<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$config = $tools->config;
if (!$tools->isRemoteUser()) {
    exit("only remote user can convert");
}

$existing_files = [];
$plex_data = $tools->callPlex("/library/sections/9/all", 'local');
foreach ($plex_data['MediaContainer']['Metadata'] as $movie) {
    $name = "{$movie['title']} ({$movie['year']})";
    foreach ($movie['Media'] as $media) {
        foreach ($media['Part'] as $part) {
            $existing_files[] = basename($part['file']);
        }
    }
}

$sync = false;
$plex_data = $tools->callPlex("/library/sections/1/all?type=1&label={$config['plex']['car_convert_label']}", 'local');
foreach ($plex_data['MediaContainer']['Metadata'] as $movie) {
    $name = "{$movie['title']} ({$movie['year']})";
    foreach ($movie['Media'] as $media) {
        foreach ($media['Part'] as $part) {
            if (strpos($part['file'], $config['video_archive_dir'])) {
                // $tools->sendError("failed converting {$name} because it's in the archive");
                continue;
            }
            $base_path = $config['remote_data_dir'];
            $filename = str_replace($config['local_data_dir'], $base_path, $part['file']);
            if (!file_exists($filename)) {
                $tools->sendError("failed converting {$name} because it's not on remote: {$filename}");
                continue;
            }
            $new_file = pathinfo($filename, PATHINFO_FILENAME) . ".avi";
            $new_filename = "{$base_path}/{$config['car_movies_dir']}/{$new_file}";
            if (file_exists($new_filename) || in_array($new_file, $existing_files)) {
                $tools->logToFile("already converted {$name}");
                continue;
            }
            $output = $retval = null;
            exec("ffmpeg -i \"{$filename}\" -vf scale=720:-1 -c:v mpeg4 -vtag xvid -q:v 10 -c:a libmp3lame -q:a 4 -hide_banner \"{$new_filename}\" 1>{$base_path}/logs/ffmpeg_output.log 2>&1", $output, $retval);
            if ($retval) {
                $tools->sendError("failed converting {$name}. Output: " . print_r($output, true));
                if (file_exists($new_filename)) {
                    unlink($new_filename);
                }
                continue;
            }
            $tools->logToFile("successfully converted {$name}");
            $sync = true;
        }
    }
}

if ($sync) {
    $tools->postToUrl($config['urls']['car_movies_sync'], [$config['secret_field'] => $config['secrets']['triggerScript']]);
}
