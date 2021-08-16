<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$tools->postToUrlRetry = false;

$action = $argv[1] ? $argv[1] : '';
switch ($action) {
    case 'get_access_token':
        $tools->getTraktAccessToken();
        break;
    default:
        $trakt_data = $tools->callTrakt('users/me/watched/shows', "");
        if (empty($trakt_data)) {
            $tools->sendError("failed to get trakt watched history", true);
        }
        $trakt_watched = $trakt_shows = [];
        foreach ($trakt_data as $show) {
            $show_info = $show['show'];
            $show_title = $show_info['title'];
            $show_title = parseShowTitle($show_title);
            $trakt_shows[$show_title] = $show_info;
            foreach ($show['seasons'] as $season) {
                foreach ($season['episodes'] as $episode) {
                    $trakt_watched[$show_title][$season['number']][$episode['number']] = $episode;
                }
            }
        }
        $plex_watched = [];
        foreach (['local', 'remote'] as $server) {
            $start = 0;
            $size = 3000;
            do {
                $history_data = $tools->callPlex("/status/sessions/history/all?librarySectionID=2&accountID=1&X-Plex-Container-Start={$start}&X-Plex-Container-Size={$size}", $server);
                if (empty($history_data['MediaContainer'])) {
                    $tools->sendError("failed to get plex watched history");
                    break;
                }
                $max_items = $history_data['MediaContainer']['totalSize'];
                $start = $start + $size - 1;
                foreach ($history_data['MediaContainer']['Metadata'] as $history_item) {
                    $show_title = $history_item['grandparentTitle'];
                    if (!in_array($show_title, $tools->config['shows_watching'])) {
                        continue;
                    }
                    $show_title = parseShowTitle($show_title);

                    $plex_watched[$show_title][$history_item['parentIndex']][$history_item['index']] = $history_item;
                }
            } while (($start + $size) < $max_items);
        }
        foreach ($plex_watched as $show => $show_info) {
            foreach ($show_info as $season => $season_info) {
                if ($season == 0) {
                    continue;
                }
                foreach ($season_info as $episode => $episode_info) {
                    if (!isset($trakt_shows[$show])) {
                        $show_query = str_replace('-', ' ', $show);
                        $show_query = urlencode(preg_replace('/[^\w\s]/', '', $show_query));
                        $response = $tools->callTrakt('search/show?fields=title&query=' . $show_query, '');
                        if (empty($response[0]['show'])) {
                            $tools->sendError("failed searching for {$show}");
                            continue 3;
                        }
                        $trakt_shows[$show] = $response[0]['show'];
                    }

                    if (isset($trakt_watched[$show][$season][$episode])) {
                        continue;
                    }

                    $data = [
                        'show' => $trakt_shows[$show],
                        'episode' => [
                            'season' => $season,
                            'number' => $episode,
                        ],
                        'progress' => 100,
                    ];
                    $response = $tools->callTrakt('scrobble/stop', $data);
                    if (!$response || $response['action'] != 'scrobble') {
                        $request_json = json_encode($data);
                        $response_json = json_encode($response);
                        $tools->sendError("scrobble failed. request: {$request_json}, response: {$response_json}");
                        continue;
                    }
                    $tools->logToFile("{$show} S{$season}E{$episode} marked as watched");
                    sleep(1);
                }
            }
        }
        break;
}

function parseShowTitle($show_title)
{
    $show_title = preg_replace('/\s\(\d{4}\)/', '', $show_title);
    $show_title = preg_replace('/\s\(\w{2}\)/', '', $show_title);

    return $show_title;
}
