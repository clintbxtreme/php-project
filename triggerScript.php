<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();

if (!isset($_REQUEST['script'])) exit;

$script_directory = $tools->config['script_directory'];
$sync_script = $tools->config['sync_script'];

switch ($_REQUEST['script']){
    case 'video_sync':
        $tools->logToFile("running rsync videos");
        exec("{$script_directory}/{$sync_script} v & &>/dev/null");
        break;
    case 'music_sync':
        $tools->logToFile("running rsync music");
        exec("{$script_directory}/{$sync_script} m & &>/dev/null");
        break;
    case 'video_archive':
        $tools->logToFile("running videoArchive");
        exec("{$script_directory}/videoArchive.sh archive & &>/dev/null");
        break;
    case 'car_movies_sync':
        $tools->logToFile("running rsync for car movies");
        exec("{$script_directory}/{$sync_script} car_movies & &>/dev/null");
        break;
}

