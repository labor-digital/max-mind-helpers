<?php
/**
 * User: Martin Neundorfer
 * Date: 07.06.2018
 * Time: 09:13
 * Vendor: LABOR.digital
 */

namespace LaborDigital\MaxMindHelpers;

use Psr\SimpleCache\CacheInterface;

/**
 * Class Reader
 * Basically a wrapper for the max mind db reader which can take a simple cache interface to
 * cache the queries and save performance.
 *
 * @package Labor\MaxmindGeoIpHelpers
 */
class Reader {
	
	/**
	 * @var \Psr\SimpleCache\CacheInterface
	 */
	protected $cache;
	
	/**
	 * @var \MaxMind\Db\Reader
	 */
	protected $maxMindReader;
	
	/**
	 * Configuration of the class
	 * @var array
	 */
	protected $config = [
		// How long the cache entries should stay valid
		'cacheTtl' => 60 * 60 * 12,
	];
	
	/**
	 * Reader constructor.
	 *
	 * @param \MaxMind\Db\Reader                   $maxMindReader
	 * @param \Psr\SimpleCache\CacheInterface|NULL $cache
	 */
	public function __construct(\MaxMind\Db\Reader $maxMindReader, CacheInterface $cache = NULL) {
		$this->cache = $cache;
		$this->maxMindReader = $maxMindReader;
	}
	
	/**
	 * Helper to overwrite the predefined configuration options distributed with this class.
	 * See $this->config[] for possible $key's
	 *
	 * @param string $key   The option key to overwrite
	 * @param mixed  $value The value to overwrite with
	 *
	 * @return $this
	 */
	public function setConfig(string $key, $value) {
		if (!isset($this->config[$key])) throw new \InvalidArgumentException('The given config key: "' . $key . '" is not valid!');
		$this->config[$key] = $value;
		return $this;
	}
	
	/**
	 * Helper to find the current client's ip address
	 * @return string
	 */
	public function getClientIp(): string {
		// Check headers for IP address
		if (isset($_SERVER['HTTP_CLIENT_IP']))
			return $_SERVER['HTTP_CLIENT_IP'];
		else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
			return $_SERVER['HTTP_X_FORWARDED_FOR'];
		else if (isset($_SERVER['HTTP_X_FORWARDED']))
			return $_SERVER['HTTP_X_FORWARDED'];
		else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
			return $_SERVER['HTTP_FORWARDED_FOR'];
		else if (isset($_SERVER['HTTP_FORWARDED']))
			return $_SERVER['HTTP_FORWARDED'];
		else if (isset($_SERVER['REMOTE_ADDR']))
			return $_SERVER['REMOTE_ADDR'];
		else
			return 'UNKNOWN';
	}
	
	/**
	 * Returns all information we have about given ip.
	 *
	 * @param string $ip Either a specific ip to look up or leave it empty to find information about the current
	 *                   client's ip
	 *
	 * @return array|null
	 * @throws \MaxMind\Db\Reader\InvalidDatabaseException
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
	public function getInformation(string $ip = '') {
		
		// Get current address if we did not get one provided
		if (empty($ip)) $ip = $this->getClientIp();
		
		// Check if we got a cache
		if (isset($this->cache)) {
			$cacheKey = 'maxmind-ip-location-cache-' . md5($ip);
			if ($this->cache->has($cacheKey))
				return \GuzzleHttp\json_decode($this->cache->get($cacheKey), TRUE);
		}
		
		// Request reader information
		$location = $this->maxMindReader->get($ip);
		if (empty($location)) return NULL;
		
		// Store value to cache
		if (isset($cacheKey))
			$this->cache->set($cacheKey, \GuzzleHttp\json_encode($location), $this->config['cacheTtl']);
		
		// Done
		return $location;
	}
	
	/**
	 * Finds only the coordinates of a given ip address
	 *
	 * @param string $ip Either a specific ip to look up or leave it empty to find the location of the current
	 *                   client's ip
	 *
	 * @return array|null
	 * @throws \MaxMind\Db\Reader\InvalidDatabaseException
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
	public function getLocation(string $ip = '') {
		$location = $this->getInformation($ip);
		if (empty($location)) return NULL;
		if (!isset($location['location'])) return NULL;
		return array_intersect_key($location['location'], ['latitude' => TRUE, 'longitude' => TRUE]);
	}
}