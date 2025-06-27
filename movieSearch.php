<?php

chdir(__DIR__);

require_once "tools.php";

$tools = new Tools();
$movie_search = new MovieSearch($tools);
$src = $_REQUEST['src'];
$slack_url = $tools->config['movie_search'][$src]['slack_url'];

$tools->logToFile($_REQUEST);

$result = [];
$title_search = preg_replace('/\[.*/', '', $_REQUEST['title']);
$title = $movie_search->cleanTitle($title_search);
if ($title) {
	$year = $movie_search->cleanYear($title_search);
	if ($year && $year < 2019) {
        $tools->logToFile("Skipping {$_REQUEST['title']} as it is before 2019");
        exit;
    }
	$results = $movie_search->search($title);
	if ($results && is_array($results)) {
		$result = $movie_search->matchResults($title, $year, $results);
	} else {
		$tools->logToFile("No tmdb results found. Response: " . json_encode($results));
	}
}

if (!$result) {
	if ($src == 'released') {
        $tools->logToFile("Skipping {$_REQUEST['title']} as it was not found in TMDB and it's a released movie");
        exit;
    }
	$content = "No TMDB result for {$_REQUEST['title']}";
	$title_link = $_REQUEST['url'];
} else {
	$details = $movie_search->getDetails($result['id']);
	list($mpa, $mpa_found) = $movie_search->getMpa($details);
	if ($src == 'released' && !$mpa_found) {
        $tools->logToFile("Skipping {$_REQUEST['title']} as it's a released movie without MPA rating");
        exit;
    }
	if ($mpa == 'R') {
        $tools->logToFile("Skipping {$_REQUEST['title']} as it has an R rating");
        exit;
    }
    $thumb_url_base = "http://image.tmdb.org/t/p/w780";
    if ($result['poster_path'] != null) {
        $thumb_url = $thumb_url_base . $result['poster_path'];
}   elseif ($result['backdrop_path'] != null) {
        $thumb_url = $thumb_url_base . $result['backdrop_path'];
    } else {
        $tools->logToFile("No poster or backdrop found for {$result['title']}");
        $thumb_url = "";

    }
	$genres = $movie_search->getGenres($details);
	$content = "{$mpa}\n{$genres}\n{$details['release_date']}\n{$details['overview']}";
	$title_link = "http://www.imdb.com/title/{$details['imdb_id']}/";
}
$payload = array(
	"attachments" => array(
		array(
			"title" => $_REQUEST['title'],
			"title_link" => $title_link,
			"thumb_url" => $thumb_url,
			"text" => $content,
			"mrkdwn_in" => array("text"),
			"fallback" => $_REQUEST['title'],
		)
	)
);

$payload_json = json_encode($payload);

$distinct_file = "/tmp/ms-" . base64_encode($_REQUEST['title']);
if (file_exists($distinct_file)) {
	return;
}

$tools->postToUrl($slack_url, ["payload" => $payload_json]);

touch($distinct_file);


class MovieSearch
{
	private $tools;

	public function __construct(Tools $tools)
	{
		$this->tools = $tools;
	}

	function call_tmdb($url)
	{
		$file_contents = $this->tools->postToUrl($url);
		$result = json_decode($file_contents, true);
		if (isset($result['errors'])) {
			$this->tools->logToFile("TMDB error: url - {$url}, error(s) - " . implode(', ', $result['errors']));
			$result = [];
		}
		return $result;
	}

	function getApiKey()
	{
		return $this->tools->config['themoviedb_key'];
	}

	function search($title)
	{
		$query = urlencode($title);
		$api_key = $this->getApiKey();
		$url = "https://api.themoviedb.org/3/search/movie?api_key={$api_key}&query={$query}";
		return $this->call_tmdb($url);
	}

	function getDetails($id)
	{
		$api_key = $this->getApiKey();
		$url = "https://api.themoviedb.org/3/movie/{$id}?api_key={$api_key}&append_to_response=releases";
		return $this->call_tmdb($url);
	}

	function matchResults($title, $year, $results)
	{
		if ($results['total_results'] == 1) {
			return $results['results'][0];
		}
		$possible_results = [];
		foreach ($results['results'] as $result) {
			if ($title == $this->cleanTitle($result['title'])) {
				$possible_results[] = $result;
				if ($year && $year == $this->cleanYear($result['release_date'])) {
					return $result;
				}
			}
		}
		if (count($possible_results) == 1) {
			return $possible_results[0];
		} elseif (count($possible_results) > 1) {
			$this->tools->logToFile("too many possible results for {$title}");
		}
	}

	function cleanTitle($title_raw)
	{
		$title = preg_replace('/[^\w\s]|\d{4}/', '', $title_raw);
		$title = strtolower(trim($title));
		if (!$title) {
			$this->tools->logToFile("no title found from {$title_raw}");
		}
		return $title;
	}

	function cleanYear($title)
	{
		$year = preg_replace('/\D|\b\d{1,3}\b/', '', $title);
		$year = intval($year);
		if (strlen($year) != 4) {
			$this->tools->logToFile("bad year of '{$year}' found from {$title}");
		}
		return $year;
	}

	function getMpa($details)
	{
		$possible_mpa = [];
		if (is_array($details['releases']['countries'])) {
			foreach ($details['releases']['countries'] as $release) {
				if ($release['iso_3166_1'] == 'US' && $release['certification']) {
					return [$release['certification'], true];
				}
				if ($release['certification']) {
					$possible_mpa[] = "{$release['iso_3166_1']}: {$release['certification']}";
				}
			}
		}

		if ($possible_mpa) {
			return [implode(", ", $possible_mpa), true];
		} else {
			$this->tools->logToFile("no mpa found. details:");
			$this->tools->logToFile($details);
			return ["N/A", false];
		}
	}

	function getGenres($details)
	{
		if (!$details['genres'] || !count($details['genres'])) {
			$this->tools->logToFile("no genre found. details:");
			$this->tools->logToFile($details);
			return "No Genre";
		}
		$genres = array();
		foreach ($details['genres'] as $genre) {
			$genres[] = $genre['name'];
		}
		return implode(", ", $genres);
	}
}
