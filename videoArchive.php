<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$config = $tools->config;

$script_path = "{$config['urls']['local']}/videoArchive.php?{$config['secret_field']}={$config['secrets']['videoArchive']}";

$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : '';

if ($action != 'archive') {
    $start = 0;
    $size = 3000;
    $last_viewed = [];
    do {
        $history_data = $tools->callPlex("/status/sessions/history/all?librarySectionID=1&X-Plex-Container-Start={$start}&X-Plex-Container-Size={$size}", 'remote');
        $max_items = $history_data['MediaContainer']['totalSize'];
        $start = $start + $size - 1;
        foreach ($history_data['MediaContainer']['Metadata'] as $history_item) {
            $rating_key = $history_item['ratingKey'] ? $history_item['ratingKey'] : null;
            if ($rating_key) {
                if (isset($last_viewed[$rating_key]) && $history_item['viewedAt'] > $last_viewed[$rating_key]) {
                    $last_viewed[$rating_key] = $history_item['viewedAt'];
                }
            }
        }
    } while (($start + $size) < $max_items);

    $movies = [];
    $movies_data = $tools->callPlex("/library/sections/1/all", 'remote');
    foreach ($movies_data['MediaContainer']['Metadata'] as $movie) {
        $viewed_at = $last_viewed[$movie['ratingKey']] ? $last_viewed[$movie['ratingKey']] : $movie['addedAt'];
        $movies[$viewed_at . '---' . $movie['title']] = $movie;
    }
    ksort($movies);
    if ($action == 'archive_recent') {
        $movie = current($movies);
        if (!empty($last_viewed[$movie['ratingKey']])) {
            $viewed_at = date("m/d/Y H:i:s", $last_viewed[$movie['ratingKey']]);
            $msg_extra = " [last watched {$viewed_at}]";
        } else {
            $added_at = date("m/d/Y H:i:s", $movie['addedAt']);
            $msg_extra = " [added {$added_at}]";
        }
        $tools->postToSlack("archiving {$movie['title']} ({$movie['year']}){$msg_extra}");
        foreach ($movie['Media'] as $file_info) {
            $files_to_archive[] = $file_info['Part'][0]['file'];
        }
        $_REQUEST['files'] = $files_to_archive;
        $action = 'archive';
    }
}

if ($action == 'archive') {
    if (isset($_REQUEST['files']) && $_REQUEST['files']) {
        file_put_contents($tools->config['video_archive_file'], implode("\n", $_REQUEST['files']) . "\n", FILE_APPEND);
        $tools->postToUrlRetry = false;
        $tools->curl_timeout = 30;
        $return = $tools->postToUrl("{$config['urls']['local']}/triggerScript.php?script=video_archive", [$config['secret_field'] => $config['secrets']['triggerScript']]);
	}
    if (isset($_REQUEST['list']) && $_REQUEST['list'] == 'all') {
        header("Location: {$script_path}");
    }
    exit;
}

$files_html = '';
foreach ($movies as $key => $movie) {
    list($viewed_at, $title) = explode('---', $key);
    $file = $movie['Media'][0]['Part'][0]['file'];
    $image = $tools->createPlexUrl($movie['thumb'], "remote", true);
    $viewedAtDt = date('m/d/y', $viewed_at);
    $summary = "{$movie['title']} ({$movie['year']}) [{$viewedAtDt}] - {$movie['summary']}";
    $files_html .= "<div class='files'>
						<span class='checkbox_span'>
							<input type='checkbox' name='files[]' value=\"{$file}\" title='{$file}' class='checkbox'>
						</span>
						<span class='image_span'>
						    <img src='{$image}' width='150' height='225' loading='lazy' alt='{$summary}' title='{$summary}' class='images'/>
                        </span>
					</div>";
}
print <<<EOD
<!doctype html>
<html>
<head>

<title>Video Archive</title>
<meta name="viewport" content="initial-scale=1, maximum-scale=1">

<style>
    .files {
        margin: 0 auto;
        max-width:255px;
        display:flex;
    }
    .checkbox_span {
        border: 1px solid;
        width:90px;
        height:90px;
        align-self:center;
        margin: 5px;
    }
    .checkbox {
        width:90px;
        height:90px;
        margin:0;
    }
    .image_span {
      position: relative;
      display: inline-block;
    }
    .image_span #tooltip {
      width: 300px;
      background-color: black;
      color: #fff;
      text-align: center;
      padding: 5px 0;
      border-radius: 6px;
      position: absolute;
      z-index: 1;
    }
</style>
<script>
    document.addEventListener('click', function (event) {
        if (event.target.matches('.images')) {
            var title_el = document.getElementById("tooltip")
            if (title_el) {
                title_el.remove()
            } else {
                var span = document.createElement("span")
                span.id = "tooltip"
                var content = document.createTextNode(event.target.title)
                span.appendChild(content)
                event.target.after(span)
            }
        }
    }, false);
</script>
</head>
<body>
    <form action='{$script_path}' method='post'>
        {$files_html}
        <div style='margin: 0 auto; max-width:500px; display:flex; position: fixed; bottom: 0; left: 0; right: 0;'>
            <input type='hidden' name='list' value='all'>
            <button type='submit' name='action' value='archive' style='width:500px; height:100px; font-size:30px;'>Archive</button>
        </div>
    </form>
</body>
</html>
EOD;
