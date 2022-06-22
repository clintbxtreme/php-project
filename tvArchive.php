<?php
chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();

$accounts_data = $tools->callPlex("/accounts", 'remote');
if (!$accounts_data) {
    $tools->sendError("failed retrieving accounts from plex", true);
}
foreach ($accounts_data['MediaContainer']['Account'] as $account) {
    $accounts[$account['id']] = $account;
}
$watched = $files_to_archive = $max_viewed_at = [];
foreach (['local', 'remote'] as $server) {
    $start = 0;
    $size = 3000;
    do {
        $history_data = $tools->callPlex("/status/sessions/history/all?librarySectionID=2&X-Plex-Container-Start={$start}&X-Plex-Container-Size={$size}", $server);
        if (!$history_data) {
            $tools->sendError("failed retrieving TV history from plex", true);
        }
        $max_items = $history_data['MediaContainer']['totalSize'];
        $start = $start + $size;
        foreach ($history_data['MediaContainer']['Metadata'] as $history_item) {
            $index = $history_item['parentIndex'] . sprintf('%02d', $history_item['index']);
            $name = $accounts[$history_item['accountID']]['name'];
            $show = $history_item['grandparentTitle'];
            // only look at recent history
            if ($history_item['viewedAt'] < strtotime("120 days ago")) {
                continue;
            }
            $viewed_at = isset($max_viewed_at[$show][$name]) ? $max_viewed_at[$show][$name] : 0;
            // not the latest viewed episode
            if ($history_item['viewedAt'] < $viewed_at) {
                continue;
            }
            $watched[$show][$name] = $index;
            $max_viewed_at[$show][$name] = $history_item['viewedAt'];
        }
    } while ($start < $max_items);
}
$shows_data = $tools->callPlex("/library/sections/2/search?type=2", 'remote');
if (!$shows_data) {
    $tools->sendError("failed retrieving TV show data from plex", true);
}
foreach ($shows_data['MediaContainer']['Metadata'] as $show) {
    $show_rating_key = $show['ratingKey'];
    $show_title = $show['title'];
    $tools->logToFile("processing {$show_title}");
    $episodes_data = $tools->callPlex("/library/metadata/{$show_rating_key}/allLeaves", 'remote');
    if (!$episodes_data) {
        $tools->sendError("failed retrieving {$show_title} episode data from plex");
        continue;
    }
    foreach ($episodes_data['MediaContainer']['Metadata'] as $ep) {
        $keep = false;
        $ep_key = $ep['parentIndex'] . sprintf('%02d', $ep['index']);
        $episode_name = "{$show_title} - S{$ep['parentIndex']}E{$ep['index']}";
        // if no one is watching it, wait x days
        if (!isset($watched[$show_title]) && $ep['updatedAt'] > strtotime("60 days ago")) {
            $keep = true;
        } elseif (isset($watched[$show_title])) {
            foreach ($watched[$show_title] as $name => $max_watched) {
                // wait till everyone has watched it
                if ($max_watched < $ep_key) {
                    $tools->logToFile("{$name} needs to watch {$episode_name}");
                    $keep = true;
                }
            }
        }
        if (!$keep) {
            $tools->logToFile("{$episode_name} marked for archival");
            foreach ($ep['Media'] as $file_info) {
                $files_to_archive[] = $file_info['Part'][0]['file'];
            }
        }
    }
}
if ($files_to_archive) {
    $params = [
        $tools->config['secret_field']   => $tools->config['secrets']['videoArchive'],
        'action' => 'archive',
        'files'  => $files_to_archive,
    ];
    $tools->postToUrlRetry = false;
    $tools->curl_timeout = 30;
    $tools->postToUrl($tools->config['urls']['local'] . "/videoArchive.php", http_build_query($params));
    if ($tools->curl_error || $tools->curl_error_number || $tools->curl_http_code != 200) {
        $tools->sendError("Failed calling videoArchive.php", true);
    }
}
