<?php

class Tools
{
	public $cookie_file = '';
	public $postToUrlRetry = true;
	public $postToUrlFollowRedirect = false;
	public $secret = '';
	public $curl_timeout = 10;
	public $curl_response_raw = '';
	public $curl_response_header = '';
	public $curl_error = null;
	public $curl_error_number = null;
	public $curl_http_code = null;
    public $debug_mode = false;
    public $config;

    private $redis;
    private $trakt_url = "https://api.trakt.tv";
    private $plex_urls;

    /**
     * Tools constructor.
     */
    public function __construct()
    {
        $this->config = include "config.php";
        global $argv;
        global $_REQUEST;
        if (isset($argv)) {
            $args = array_slice($argv, 1);
            if ($args) {
                if (strpos($args[0], "=")) {
                    parse_str(implode('&', $args), $_REQUEST);
                }

                if ($args[0] == 'debug' || (isset($_REQUEST['debug']) && $_REQUEST['debug'])) {
                    $this->debug_mode = true;
                }
            }
        }
        $this->checkSecret();
    }

    public function logToFile($info, $exit=false, $debug_msg=false) {
		if ($debug_msg && !$this->debug_mode) {
            return;
        }
	    if (is_array($info)) {
			$info = json_encode($info);
		}
        $log = "[".date("Y-m-d H:i:s")."] {$info}\n\n";
        
        $log_base = "/tmp/";
        $log_extention = ".log";
        if ($this->isRemoteUser()) {
            $log_base = "{$_SERVER['HOME']}/logs/";
            $log_extention = "/log";
        }
        $log_file = $log_base . $this->scriptName() . $log_extention;
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0770, true);
        }

	    if ($this->debug_mode) {
            echo $log;
        }

        if ($log_file) {
			file_put_contents($log_file, $log, FILE_APPEND);
		} else {
			error_log("no log_file set in {$_SERVER['PHP_SELF']}");
		}
		if ($exit) exit;
	}

	public function sendText($number, $text) {
		$this->logToFile("sending '{$text}' to '{$number}'");
		$this->sendToIfttt($this->config['ifttt']['triggers']['sms'], ['value1' => $number, 'value2' => $text]);
	}

	public function sendToIfttt($trigger, $values = []) {
		$url = "https://maker.ifttt.com/trigger/{$trigger}/with/key/{$this->config['ifttt']['key']}";
		$result = $this->postToUrl($url, $values);
		if (strpos($result, 'Congratulations') === false) {
			$this->logToFile($result);
		}
	}

    public function logAndSlack($msg) {
        $this->logToFile($msg);
        $this->postToSlack($msg);
    }

	public function sendError($error, $exit = false) {
        $this->logToFile($error);
        $fields = [
            "text"    => $error,
            "channel" => $this->config['slack']['error_channel'],
        ];
        $this->postToSlack($fields);

        if ($exit) {
            exit;
        }
    }

	public function postToSlack($fields, $url = null) {
		if (is_array($fields)) {
			$fields['token'] = $this->config['slack']['token'];
			$msg = $fields['text'] ? $fields['text'] : $fields['attachments'][0]['fallback'];
		} else {
			$msg = $fields;
			$fields = ["text" => $fields];
		}
		if ($fields) {
			$fields = json_encode($fields);
		}

        $slack_url = $url ? $url : $this->slackUrl();
		$this->logToFile("sending '{$msg}' to slack");
		$result_json = $this->postToUrl($slack_url, $fields);
		$result = json_decode($result_json, true);
		if ($result_json != 'ok' && !$result['ok']) {
			$this->logToFile("Slack result: " . $result_json);
		}
	}

	public function postToUrl($url, $fields = [], $headers = [], $method = null, $retry_count = 0) {
        $response_header = array();
        $error = false;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->curl_timeout);

        if ($this->postToUrlFollowRedirect) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }
		if ($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		} else {
			curl_setopt($ch, CURLOPT_HEADER, 0);
		}

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        if ($this->cookie_file) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
			curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
		}

        if ($method) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

		if ($fields) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
			if ($method && $method != "POST") {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
		}

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($handle, $data) use (&$response_header) {
            if (trim($data)) {
                $parts = explode(":", $data, 2);
                if (count($parts)==2) {
                    $response_header[$parts[0]] = trim($parts[1]);
                } else {
                    $response_header[] = trim($data);
                }
            }
            return strlen($data);
        });

		$result = curl_exec($ch);
        $this->curl_response_raw = $result;
        $this->curl_response_header = $response_header;
        $curl_err = curl_error($ch);
        $curl_err_num = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->curl_error = $curl_err;
        $this->curl_error_number = $curl_err_num;
        $this->curl_http_code = $http_code;
        $sleep_seconds = 5;
        if($curl_err_num) {
            $error = "Curl err to {$url}: {$curl_err}; http code: {$http_code}; curl num: {$curl_err_num}";
        } elseif(!in_array($http_code, [200, 201, 202])) {
            if($http_code == 429) {
                $sleep_seconds = $response_header['Retry-After'] ? $response_header['Retry-After'] : $sleep_seconds;
                $sleep_seconds = $response_header['retry-after'] ? $response_header['retry-after'] : $sleep_seconds;
                $error = "Hit rate limit. Response header: " . json_encode($response_header);
            } else {
                $error = "Call err to {$url}, code {$http_code} " . print_r($result, true);
            }
        }
		curl_close($ch);
        if ($error) {
            if ($this->postToUrlRetry) {
                if ($retry_count >= 5) {
                    $this->sendError("postToUrl error = " . $error, true);
                }
                $retry_count ++;
                $this->logToFile("Sleep: {$sleep_seconds}s; retry count: {$retry_count}; url: {$url}; error: {$error}");
                sleep($sleep_seconds);
                return $this->postToUrl($url, $fields, $headers, $method, $retry_count);
            } else {
                if ($error == strip_tags($error)) {
                    $this->sendError($error);
                }
                return null;
            }
        }
		return $result;
	}

    public function downloadFile($url, $dest)
    {
        $ch = curl_init();
		curl_setopt ($ch, CURLOPT_URL, $url);
		curl_setopt ($ch, CURLOPT_FILE, is_resource($dest) ? $dest : fopen($dest, 'w'));
		curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt ($ch, CURLOPT_FAILONERROR, true);
        $return = curl_exec($ch);

        $curl_err = curl_error($ch);
        $curl_err_num = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->curl_error = $curl_err;
        $this->curl_error_number = $curl_err_num;
        $this->curl_http_code = $http_code;
        
        curl_close($ch);
    }

	public function callPlexPy($fields) {
		$fields['apikey'] = $this->config['tautulli']['key'];
		$url_params = http_build_query($fields);
		$url = "{$this->config['tautulli']['url']}?{$url_params}";
		$file_contents = $this->postToUrl($url);
		$data = json_decode($file_contents, true);
		return $data;
	}

	public function callOmbi($endpoint, $method = null, $params = [], $api_version = "v1") {
        $postToUrlRetry = $this->postToUrlRetry;
	    $this->postToUrlRetry = false;
	    $url = "{$this->config['ombi']['url']}/{$api_version}/{$endpoint}";
        $headers = [
			"Accept: application/json",
            "ApiKey: {$this->config['ombi']['key']}"
		];

        $this->logToFile("calling {$endpoint} on ombi", false, true);
		$file_contents = $this->postToUrl($url, $params, $headers, $method);
        $this->logToFile("done calling {$endpoint} on ombi", false, true);
		$data = json_decode($file_contents, true);
        $this->postToUrlRetry = $postToUrlRetry;
		return $data;
	}

	public function callPlex($endpoint, $server, $method = null) {
        $postToUrlRetry = $this->postToUrlRetry;
        $this->postToUrlRetry = false;
        $curlTimeout = $this->curl_timeout;
        $this->curl_timeout = 60;
        $url = $this->createPlexUrl($endpoint, $server);
        $headers = [
            "Accept: application/json",
            "X-Plex-Token: " . $this->getPlexToken()
		];

        $this->logToFile("calling {$endpoint} on {$server}", false, true);
        $file_contents = $this->postToUrl($url, [], $headers, $method);
        $this->logToFile("done calling {$endpoint} on {$server}", false, true);
        $data = json_decode($file_contents, true);
        $this->postToUrlRetry = $postToUrlRetry;
        $this->curl_timeout = $curlTimeout;
		return $data;
	}

    public function getPlexWatchlist() {
        $postToUrlRetry = $this->postToUrlRetry;
        $this->postToUrlRetry = false;
        $curlTimeout = $this->curl_timeout;
        $this->curl_timeout = 60;
        $headers = [
            "Accept: application/json",
            "X-Plex-Token: " . $this->getPlexToken()
		];
        $url = "https://discover.provider.plex.tv/library/sections/watchlist/all?X-Plex-Container-Size=100";
        $this->logToFile("getting Plex watchlist", false, true);
        $file_contents = $this->postToUrl($url, [], $headers);
        $this->logToFile("done getting Plex watchlist", false, true);
        $data = json_decode($file_contents, true);
        $this->postToUrlRetry = $postToUrlRetry;
        $this->curl_timeout = $curlTimeout;
        return $data;
    }

    public function getTraktAccessToken()
    {
        $info = $this->getTraktSettings();
        $headers = [
            "Content-type: application/json",
            "trakt-api-version: 2",
        ];
        $data = json_encode(['client_id' => $info['client_id']]);
        $request_time = time();
        $file_contents = $this->postToUrl("{$this->trakt_url}/oauth/device/code", $data, $headers);
        $response = json_decode($file_contents, true);
        if ($response) {
            if ($response['user_code']) {
                $this->postToSlack("Enter {$response['user_code']} at {$response['verification_url']}");
                do {
                    sleep($response['interval']);
                    $data = json_encode(
                        [
                            'code'          => $response['device_code'],
                            'client_id'     => $info['client_id'],
                            'client_secret' => $info['client_secret'],
                        ]
                    );
                    $code_response_json = $this->postToUrl("{$this->trakt_url}/oauth/device/token", $data, $headers);
                    $code_response = json_decode($code_response_json, true);
                    if ($code_response) {
                        $this->saveTraktTokenInfo(array_merge($info, $code_response));

                        return true;
                    }
                } while (time() < $request_time + $response['expires_in']);
                $this->logToFile("token retrieval timeout");
            }
        } else {
            $this->logToFile("failure getting user code");
        }
	}

	protected function getTraktSettings()
    {
        return json_decode(file_get_contents($this->config["trakt_setings_file"]), true);
    }

    protected function refreshTraktToken()
    {
        $info = $this->getTraktSettings();
        foreach (['client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_in', 'created_at'] as $req) {
            if (empty($info[$req])) {
                $error_msg = "trakt failure: missing {$req}";
                $this->sendError($error_msg, true);
            }
        }
        if (true || time()  >= $info['created_at'] + $info['expires_in']) {
            $data = [
                "refresh_token" => $info['refresh_token'],
                "client_id"     => $info['client_id'],
                "client_secret" => $info['client_secret'],
                "redirect_uri"  => "urn:ietf:wg:oauth:2.0:oob",
                "grant_type"    => "refresh_token",
            ];
            $headers = [
                "Content-Type: application/json",
            ];
            $response_json = $this->postToUrl("{$this->trakt_url}/oauth/token", json_encode($data), $headers);
            $response = json_decode($response_json, true);
            $this->saveTraktTokenInfo(array_merge($info, $response));
        }
	}

    protected function saveTraktTokenInfo($info)
    {
        $save_data = [];
        foreach (['client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_in', 'created_at'] as $req) {
            if (empty($info[$req])) {
                $error_msg = "trakt save failure: missing {$req}";
                $this->sendError($error_msg, true);
            }
            $save_data[$req] = $info[$req];
        }
        if ($save_data) {
            file_put_contents($this->config["trakt_setings_file"], json_encode($save_data));
        }
    }

	public function callTrakt($endpoint, $data) {
        $url = "{$this->trakt_url}/{$endpoint}";
        $this->refreshTraktToken();
        $info = $this->getTraktSettings();
        $headers = [
			"Content-type: application/json",
			"Accept: application/json",
			"trakt-api-key: {$info['client_id']}",
			"trakt-api-version: 2",
            "Authorization: Bearer {$info['access_token']}",
		];

        if (is_array($data)) {
            $data = json_encode($data);
        }

		$file_contents = $this->postToUrl($url, $data, $headers);
		$data = json_decode($file_contents, true);
		return $data;
	}

	public function createPlexUrl($endpoint, $server, $include_token = false) {
        $url = $this->getPlexUrl($server);
        $url .= $endpoint;
        if ($include_token) {
            $token = $this->getPlexToken();
            $prefix = "?";
            if (strpos($endpoint, '?')) {
                $prefix = "&";
            }
            $url .= "{$prefix}X-Plex-Token={$token}";
        }

        return $url;
    }

	public function getCurrentTemp() {
		$data = $this->callOpenWeatherMap('weather', ['id'=>$this->config['openweathermap']['city_id'], 'units'=>'imperial']);
		return $data['main']['temp'];
	}

	private function callOpenWeatherMap($endpoint, $params = []) {
		$api_key = $this->config['openweathermap']['api_key'];
		$api_url = "https://api.openweathermap.org/data/2.5/{$endpoint}";
		$params['APPID'] = $api_key;
		$params_encoded = http_build_query($params);
		$result_json = $this->postToUrl($api_url . '?' . $params_encoded);
		$result = json_decode($result_json, true);
		return $result;
	}

	private function getPlexCloudUrl() {
		$token = $this->getPlexCloudToken();
		$data = file_get_contents("https://plex.tv/users/cpms?X-Plex-Token={$token}");
		preg_match('/http.*\.services/', $data, $matches);
		$url = '';
		if ($matches) {
			$url = $matches[0];
		}
		return $url;
	}

	private function getPlexCloudToken() {
		return $this->config['plex']['cloud_token'];
	}

	private function getPlexToken() {
		return $this->config['plex']['token'];
	}

    public function getPlexUrl($server) {
        if ($this->plex_urls) {
            return $this->plex_urls[$server];
        }

        $url = "https://plex.tv/api/v2/resources";
        $data = [
            "X-Plex-Client-Identifier" => 1234,
            "X-Plex-Token" => $this->getPlexToken(),
            "includeHttps" => true,
        ];
        $headers = [
			"Accept: application/json",
		];
        $file_contents = $this->postToUrl($url, $data, $headers, "GET");
		$data = json_decode($file_contents, true);
        $servers = $this->config['plex']['servers'];
        foreach($data as $d) {
            if (isset($servers[$d['name']])) {
                foreach($d['connections'] as $conn) {
                    if ($conn['local'] && in_array($_SERVER['USER'], $servers[$d['name']]['hosts'])) {
                        $this->plex_urls[$servers[$d['name']]['instance']] = "http://{$conn['address']}:{$conn['port']}";
                    } elseif (!$conn['local'] && !in_array($_SERVER['USER'], $servers[$d['name']]['hosts']) && !$conn['relay']) {
                        $this->plex_urls[$servers[$d['name']]['instance']] = $conn['uri'];
                    }
                }
            }
        }
        if (!isset($this->plex_urls[$server])) {
            $this->sendError('unable to find {$server} Plex URI');
            throw new Exception('Plex URI find failure', 1);
        }
        return $this->plex_urls[$server];
    }

	private function callOpenGarage($endpoint, $params = []) {
		$open_garage_ip = $this->getOpenGarageIp();
		$params_json = json_encode($params);
		$params['dkey'] = $this->config['opengarage']['key'];
		$url_params = http_build_query($params);
		$url = "http://{$open_garage_ip}/{$endpoint}?{$url_params}";
		$error = false;
		$timeout = 0;
		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_URL, $url);
		curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$file_contents = curl_exec($ch);
		$data = json_decode($file_contents, true);

		$curl_error_num = (curl_errno($ch)==0) ? false : true;
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if($curl_error_num) {
			$curl_error = curl_error($ch);
			$error = "Curl error: {$curl_error}; Status code: {$http_code}";
		} elseif($http_code != 200 || (isset($data['result']) && $data['result'] != 1)) {
			$error = "Call error to {$endpoint}, http_code {$http_code}, Params: {$params_json} Result: {$file_contents}";
		}
		curl_close($ch);
		if ($error) {
			$this->sendError($error);
			throw new Exception('Call Error', 1);
		}
		$msg = "{$endpoint} - params: {$params_json}, result: {$file_contents}";
		$this->logToFile($msg);
		return $data;
	}

	private function getOpenGarageIp() {
		$ip = trim(file_get_contents($this->config['opengarage']['url_file']));
		if (!$ip) {
			$this->sendError('unable to find OpenGarage IP');
			throw new Exception('IP find failure', 1);
		}
		return $ip;
	}

	public function getOpenGarageStatus() {
		$result = $this->callOpenGarage('jc');
		if (isset($result['door'])) {
			$status = ($result['door']) ? "open" : "close";
			$vehicle = ($result['vehicle']) ? "with vehicle inside" : "";
			return [$status, $vehicle];
		} else {
			$this->sendError("unable to get garage status");
			throw new Exception('OpenGarageStatus Error', 1);
		}
	}

	public function triggerOpenGarage($action) {
		//$this->slack_url = $this->config['opengarage']['slack_url'];
		if ($this->getOpenGarageStatus()[0] != $action) {
			$result = $this->callOpenGarage('cc', [$action=>1]);
			if (isset($_REQUEST['source']) && $_REQUEST['source']) {
				$via = $_REQUEST['source'];
			} else {
				$via = json_encode($this->decodeUserAgent($_SERVER['HTTP_USER_AGENT']));
			}
			$this->logAndSlack("triggered {$action} garage via {$via}");
		} else {
			$this->logToFile("not triggering {$action} garage");
		}
	}

	public function decodeUserAgent($ua = '') {
		$fields = [
			"access_key"=>$this->config['userstack_key'],
			"output"=>"json",
			"fields"=>"type,browser.name,device.name,device.is_mobile_device,os.name",
			"ua"=>$ua,
		];
		$ua_api_url = "http://api.userstack.com/detect?".http_build_query($fields);
		$user_agent_json = @file_get_contents($ua_api_url);
		if (!$user_agent_json) {
			return $ua;
		}
		$user_agent = json_decode($user_agent_json, true);
		return $user_agent;
	}

	public function redis() {
        if (!$this->redis) {
            require $this->config['predis_file'];
            $this->redis = new Predis\Client("tcp://127.0.0.1:6379?read_write_timeout=0");
        }
        return $this->redis;
    }

	public function send404() {
		http_response_code(404);
		die();
	}

	public function checkSecret() {
        if (isset($_SERVER['REMOTE_ADDR']) && isset($_SERVER['SERVER_ADDR']) && $_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR'] && $_SERVER['REMOTE_ADDR'] != "127.0.0.1") {
            return;
        }
        if (!isset($this->config['secrets'][$this->scriptName()])) {
            return;
        }
        $secret = $this->config['secrets'][$this->scriptName()];
	    if (!isset($_REQUEST[$this->config['secret_field']]) || $_REQUEST[$this->config['secret_field']] != $secret) {
            $this->logToFile("invalid secret. request: " . json_encode($_REQUEST));
	        $this->send404();
        }
    }

    public function loadJson() {
        if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json') {
            $HTTP_RAW_POST_DATA = file_get_contents("php://input");
            if ($HTTP_RAW_POST_DATA) {
                $_REQUEST = array_merge($_REQUEST, json_decode($HTTP_RAW_POST_DATA, true));
            }
        }
    }

    public function isRemoteUser() {
        if (isset($_SERVER['USER']) && $_SERVER['USER'] == $this->config["remote_user"]) {
            return true;
        }
        return false;
    }

    public function scriptName() {
        return pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME);
    }

    public function slackUrl() {
        if (!empty($this->config['slack']['urls'][$this->scriptName()])) {
            return $this->config['slack']['urls'][$this->scriptName()];
        }
        return $this->config['slack']['urls']['default'];
    }

    public function cliOnly() {
        if (php_sapi_name() !== 'cli') {
            exit('access denied');
        }
    }

    public function getPublicIp() {
        return file_get_contents("https://ipecho.net/plain");
    }
}