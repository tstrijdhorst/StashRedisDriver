<?php

/*
 * This file is part of an alternative driver package and is not part of the Stash Package.
 * It is however derived from that code.
 */

namespace Respondens\Stash\Driver;

use Stash\Driver\AbstractDriver;
use Stash\Utilities;

/**
 * The Redis driver is used for storing data on a Redis system. This class uses
 * the PhpRedis extension to access the Redis server.
 *
 * @package Respondens\Stash
 * @author  Tim Strijdhorst <tim@decorrespondent.nl>
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Redis extends AbstractDriver {
	const SERVER_DEFAULT_HOST = '127.0.0.1';
	const SERVER_DEFAULT_PORT = 6379;
	const SERVER_DEFAULT_TTL  = 0.1;
	
	protected static $pathPrefix = 'pathdb:';
	
	protected static $redisArrayOptionNames = [
		"previous",
		"function",
		"distributor",
		"index",
		"autorehash",
		"pconnect",
		"retry_interval",
		"lazy_connect",
		"connect_timeout",
	];
	
	/**
	 * The Redis drivers.
	 *
	 * @var \Redis|\RedisArray
	 */
	protected $redis;
	
	/**
	 * The cache of indexed keys.
	 *
	 * @var array
	 */
	protected $keyCache = [];
	
	/**
	 * If this is true the keyParts will be normalized using the default Utilities::normalizeKeys($key)
	 *
	 * @var bool
	 */
	protected $normalizeKeys = false;
	
	/**
	 * The options array should contain an array of servers,
	 *
	 * The "server" option expects an array of servers, with each server being represented by an associative array. Each
	 * redis config must have either a "socket" or a "server" value, and optional "port" and "ttl" values (with the ttl
	 * representing server timeout, not cache expiration).
	 *
	 * The "database" option lets developers specific which specific database to use.
	 *
	 * The "password" option is used for clusters which required authentication.
	 *
	 * @param array $options
	 */
	protected function setOptions(array $options = array()) {
		$options += $this->getDefaultOptions();
		
		if (isset($options['normalize_keys'])) {
			$this->normalizeKeys = $options['normalize_keys'];
		}
		
		// Normalize Server Options
		if (isset($options['servers'])) {
			$unprocessedServers = (is_array($options['servers'])) ? $options['servers'] : [$options['servers']];
			unset($options['servers']);
			
			$servers = $this->processServerConfigurations($unprocessedServers);
		}
		else {
			$servers = [['server' => self::SERVER_DEFAULT_HOST, 'port' => self::SERVER_DEFAULT_PORT, 'ttl' => self::SERVER_DEFAULT_TTL]];
		}
		
		/*
		 * This will have to be revisited to support multiple servers, using the RedisArray object.
		 * That object acts as a proxy object, meaning most of the class will be the same even after the changes.
		 */
		if (count($servers) == 1) {
			$server = $servers[0];
			$redis  = new \Redis();
			
			if (isset($server['socket']) && $server['socket']) {
				$redis->connect($server['socket']);
			}
			else {
				$redis->connect($server['server'], $server['port'], $server['ttl']);
			}
			
			// auth - just password
			if (isset($options['password'])) {
				$redis->auth($options['password']);
			}
			
			$this->redis = $redis;
		}
		else {
			$redisArrayOptions = [];
			foreach (static::$redisArrayOptionNames as $optionName) {
				if (isset($options[$optionName])) {
					$redisArrayOptions[$optionName] = $options[$optionName];
				}
			}
			
			$serverArray = [];
			foreach ($servers as $server) {
				$serverString = $server['server'];
				if (isset($server['port'])) {
					$serverString .= ':'.$server['port'];
				}
				
				$serverArray[] = $serverString;
			}
			
			$redis = new \RedisArray($serverArray, $redisArrayOptions);
		}
		
		// select database
		if (isset($options['database'])) {
			$redis->select($options['database']);
		}
		
		$this->redis = $redis;
	}
	
	/**
	 * Properly close the connection.
	 */
	public function __destruct() {
		if ($this->redis instanceof \Redis) {
			try {
				$this->redis->close();
			}
			catch (\RedisException $e) {
				/*
				 * \Redis::close will throw a \RedisException("Redis server went away") exception if
				 * we haven't previously been able to connect to Redis or the connection has severed.
				 */
			}
		}
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function getData($key) {
		return unserialize($this->redis->get($this->makeKeyString($key)));
	}
	
	/**
	 * @inheritdoc
	 */
	public function storeData($key, $data, $expiration) {
		$serializedData = serialize(
			[
				'data'       => $data,
				'expiration' => $expiration,
			]
		);
		
		if ($expiration === null) {
			return $this->redis->set($this->makeKeyString($key), $serializedData);
		}
		
		$ttl = $expiration - time();
		
		// Prevent us from even passing a negative ttl'd item to redis,
		// since it will just round up to zero and cache forever.
		if ($ttl < 1) {
			return true;
		}
		
		return $this->redis->setex($this->makeKeyString($key), $ttl, $serializedData);
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function clear($key = null) {
		if ($key === null) {
			return $this->redis->flushDB();
		}
		
		$pathString = $this->makeKeyString($key, true);
		$keyString  = $this->makeKeyString($key);
		
		$this->redis->delete($keyString); // remove direct item.
		$this->deleteSubKeys($keyString); // remove all the subitems
		
		$this->keyCache[$pathString] = $this->redis->incr($pathString); // increment index for children items
		
		return true;
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function purge() {
		return true;
	}
	
	/**
	 * {@inheritdoc}
	 */
	public static function isAvailable() {
		return class_exists('Redis', false);
	}
	
	/**
	 * Turns a key array into a key string. This includes running the indexing functions used to manage the Redis
	 * hierarchical storage.
	 *
	 * @param  array $key
	 * @param  bool  $path
	 * @return string
	 */
	protected function makeKeyString($key, $path = false) {
		if ($this->normalizeKeys) {
			$key = Utilities::normalizeKeys($key);
		}
		
		$keyString = '';
		foreach ($key as $name) {
			$keyString .= $name;
			
			/*
			 * Check if there is an index available in the pathdb, that means there was a deletion of the stackparent before
			 * and we should use the index inside the pathdb to as a prefix for the sub-keys.
			 */
			$pathString = self::$pathPrefix.$keyString;
			if (isset($this->keyCache[$pathString])) {
				$index = $this->keyCache[$pathString];
			}
			else {
				$index = $this->redis->get(self::$pathPrefix.$keyString);
			}
			
			if ($index) {
				$keyString .= '_'.$index;
			}
			
			$keyString .= ':';
		}
		
		$keyString = rtrim($keyString, ':');
		
		return $path ? self::$pathPrefix.$keyString : $keyString;
	}
	
	/**
	 * @param string $keyString
	 */
	private function deleteSubKeys($keyString) {
		$iterator = null;
		
		while ($subKeys = $this->redis->scan($iterator, $keyString.'*')) {
			foreach ($subKeys as $subKey) {
				$this->redis->delete($subKey);
			}
		}
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function isPersistent() {
		return true;
	}
	
	/**
	 * @param array $unprocessedServers
	 * @return array
	 */
	protected function processServerConfigurations(array $unprocessedServers) {
		$servers = [];
		foreach ($unprocessedServers as $server) {
			$ttl = '.1';
			if (isset($server['ttl'])) {
				$ttl = $server['ttl'];
			}
			elseif (isset($server[2])) {
				$ttl = $server[2];
			}
			
			if (isset($server['socket'])) {
				$servers[] = array('socket' => $server['socket'], 'ttl' => $ttl);
				continue;
			}
			
			$host = self::SERVER_DEFAULT_HOST;
			if (isset($server['server'])) {
				$host = $server['server'];
			}
			elseif (isset($server[0])) {
				$host = $server[0];
			}
			
			$port = self::SERVER_DEFAULT_PORT;
			if (isset($server['port'])) {
				$port = $server['port'];
			}
			elseif (isset($server[1])) {
				$port = $server[1];
			}
			
			$servers[] = ['server' => $host, 'port' => $port, 'ttl' => $ttl];
		}
		
		return $servers;
	}
}
