<?php

require 'config.php';

ini_set('max_execution_time', 30);
ini_set('session.use_strict_mode', 0);

class RateLimiter {
	private $sessionLifetime;
	private $maxRequests;
	private $maxBanCount;
	private $banDuration;

	public function __construct($sessionLifetime = 60, $maxRequests = 800, $maxBanCount = 5, $banDuration = 21600) {
		$this->sessionLifetime = $sessionLifetime;
		$this->maxRequests = $maxRequests;
		$this->maxBanCount = $maxBanCount;
		$this->banDuration = $banDuration;

		$this->startSessionBasedOnIP();
		$this->initializeSessionVariables();
		$this->checkRateLimit();
	}

	private function getIp() {
		$ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

		foreach ($ipKeys as $key) {
			if (!empty($_SERVER[$key])) {
				$ip = $_SERVER[$key];
				
				if ($key === 'HTTP_X_FORWARDED_FOR') {
					// Handle the case where HTTP_X_FORWARDED_FOR contains multiple IP addresses
					$ipList = explode(',', $ip);
					$ip = trim(end($ipList)); 
				}

				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
					return $ip;
				}
			}
		}

		return false;
	}

	private function startSessionBasedOnIP() {
		$ip = $this->getIp();
		if ($ip !== false) {
			$ipHash = md5($ip);
			session_id($ipHash);
		}
		session_start();
	}

	private function initializeSessionVariables() {
		if (!isset($_SESSION['timeStart'])) {
			$_SESSION['timeStart'] = time();
		}
		if (!isset($_SESSION['countHits'])) {
			$_SESSION['countHits'] = 0;
		}
		if (!isset($_SESSION['countBans'])) {
			$_SESSION['countBans'] = 0;
		}
		if (!isset($_SESSION['timeBannedUntil'])) {
			$_SESSION['timeBannedUntil'] = 0;
		}
	}

	private function checkRateLimit() {
		if ($_SESSION['timeBannedUntil'] > time()) {
			header('HTTP/1.1 400 Bad Request');
			die('You have been banned. Please try again later.');
		}

		$sinceIntervalStart = time() - $_SESSION['timeStart'];

		if ($sinceIntervalStart > $this->sessionLifetime) {
			$_SESSION['timeStart'] = time();
			$_SESSION['countHits'] = 1;
		} else {
			$_SESSION['countHits']++;
		}

		if ($_SESSION['countHits'] > $this->maxRequests) {
			$this->handleBan();
		}
	}

	public function handleBan() {
		if ($_SESSION['countBans'] < $this->maxBanCount) {
			$_SESSION['countBans']++;
			if ($_SESSION['countBans'] >= $this->maxBanCount) {
				$this->executeBan();
			}
		}

		header('HTTP/1.1 429 Too Many Requests');
		die('Too many requests');
	}

	public function executeBan() {
		$_SESSION['timeBannedUntil'] = time() + $this->banDuration;
		header('HTTP/1.1 400 Bad Request');
		die('You have been banned.');
	}
}

class TileProxy {
	private $storage;
	private $operator;
	private $tileserver;
	private $trustedHosts;
	private $ttl;
	private $rateLimiter;

	public function __construct($storage, $operator, $tileserver, $trustedHosts, $ttl) {
		$this->storage = $storage;
		$this->operator = $operator;
		$this->tileserver = $tileserver;
		$this->trustedHosts = $trustedHosts;
		$this->ttl = $ttl;

		$this->rateLimiter = new RateLimiter();
		$this->processRequest();
	}

	private function downloadTile($z, $x, $y) {
		set_time_limit(0);
		$source = str_replace(['{x}', '{y}', '{z}'], [$x, $y, $z], $this->tileserver);

		$filePath = $this->storage . $z . '/' . $x . '/' . $y . '.png';
		if (!is_dir(dirname($filePath))) {
			mkdir(dirname($filePath), 0750, true);
		}

		$fh = fopen($filePath, 'w');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $source);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Tile Proxy, Operator: ' . $this->operator);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);

		if (!curl_exec($ch)) {
			curl_close($ch);
			fclose($fh);
			unlink($filePath);
			throw new Exception('Error providing tile');
		}

		curl_close($ch);
		fclose($fh);
	}

	private function processRequest() {
		$z = filter_input(INPUT_GET, 'z', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
		$x = filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		$y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		if ($z === false || $x === false || $y === false) {
			$this->rateLimiter->executeBan(); 
			header('HTTP/1.1 400 Bad Request');
			die('Invalid parameters');
		}

		// CORS
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if (in_array(parse_url($origin, PHP_URL_HOST), $this->trustedHosts)) {
			header('Access-Control-Allow-Origin: ' . $origin);
		}

		// Referer-Check
		$referer = $_SERVER['HTTP_REFERER'] ?? '';
		if (!empty($referer)) {
			$refererHost = parse_url($referer, PHP_URL_HOST);
			if (!in_array($refererHost, $this->trustedHosts)) {
				header('HTTP/1.1 403 Forbidden');
				die('Access denied');
			}
		}

		$tileDirectory = $this->storage . $z . '/' . $x;
		if (!is_dir($tileDirectory)) {
			mkdir($tileDirectory, 0750, true);
		}

		$tilePath = $tileDirectory . '/' . $y . '.png';
		if (file_exists($tilePath)) {
			$age = filemtime($tilePath);
			$modified = gmdate('D, d M Y H:i:s', $age) .' GMT';
			if ($age + $this->ttl <= time()) {
				$this->downloadTile($z, $x, $y);
			}
		} else {
			$this->downloadTile($z, $x, $y);
			$modified = gmdate('D, d M Y H:i:s', time()) .' GMT';
		}

		if (file_exists($tilePath)) {
			$expires = gmdate('D, d M Y H:i:s', time() + $this->ttl) .' GMT';
			header('HTTP/1.1 200 OK');
			header('Expires: ' . $expires);
			header('Last-Modified: ' . $modified);
			header('Content-Type: image/png');
			header('Cache-Control: public, max-age=' . $this->ttl);
			readfile($tilePath);
		} else {
			header('HTTP/1.1 500 Internal Server Error');
			die('Internal Server Error');
		}
	}
}

// Usage
new TileProxy($storage, $operator, $tileserver, $trustedHosts, $ttl);
