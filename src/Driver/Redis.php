<?php

/*
 * This file is part of an alternative driver package and is not part of the Stash Package.
 * It is however derived from that code.
 */

namespace ResponStash\Driver;

use Stash\Driver\AbstractDriver;
use Stash\Utilities;

/**
 * The Redis driver is used for storing data on a Redis system. This class uses
 * the PhpRedis extension to access the Redis server.
 *
 * @package ResponStash
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
		if (isset($options['servers']) && count($options['servers']) > 0) {
			$unprocessedServers = (is_array($options['servers'][0])) ? $options['servers'] : [$options['servers']];
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
			$this->redis = $this->connectToSingleRedisServer($options, $servers[0]);
		}
		else {
			$this->redis = $this->connectToMultipleRedisServers($options, $servers);
		}
		
		// select database
		if (isset($options['database'])) {
			$this->redis->select($options['database']);
		}
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
		
		$keyString = $this->makeKeyString($key);
		$this->redis->delete($keyString); // remove direct item.
		
		/**
		 * If the key has subkeys that means that we will have to remove them too.
		 * But first we create a new index for the stackparent in the pathdb so we are sure there will be no new
		 * subkeys added while we are deleting them.
		 */
		if ($this->hasSubKeys($keyString)) {
			$pathString                  = $this->makeKeyString($key, true);
			$this->keyCache[$pathString] = $this->redis->incr($pathString); //Create a new index and save it in the key cache
			
			$this->deleteSubKeys($keyString); // remove all the subitems
		}
		
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
	 * @param  array $keyParts
	 * @param  bool  $path
	 * @return string
	 * @throws \Exception
	 */
	protected function makeKeyString($keyParts, $path = false) {
		if ($this->normalizeKeys) {
			$keyParts = Utilities::normalizeKeys($keyParts);
		}
		
		$keyString = '';
		foreach ($keyParts as $keyPart) {
			if (!$this->normalizeKeys && (strpos($keyPart, ':') || strpos($keyPart, '_'))) {
				throw new \Exception('You cannot use `:` or `_` in keys if key_normalization is off.');
			}
			
			$keyString .= $keyPart;
			
			/*
			 * Check if there is an index available in the pathdb, that means there was a deletion of the stackparent before
			 * and we should use the index inside the pathdb to as a prefix for the sub-keys.
			 *
			 * However if we are generating the path this should not be included since the index will never get higher than 1 then.
			 */
			if (!$path) {
				$pathString = self::$pathPrefix.$keyString;
				if (isset($this->keyCache[$pathString])) {
					$index = $this->keyCache[$pathString];
				}
				else {
					$index = $this->redis->get($pathString);
				}
				
				if ($index) {
					$keyString .= '_'.$index;
				}
			}
			
			$keyString .= ':';
		}
		
		$keyString = rtrim($keyString, ':');
		
		return $path ? self::$pathPrefix.$keyString : $keyString;
	}
	
	/**
	 * @param $keyString
	 * @return bool
	 */
	private function hasSubKeys($keyString) {
		return $this->redis->scan($iterator, $keyString.'*') !== false;
	}
	
	/**
	 * @param string $keyString
	 * @return bool
	 */
	private function deleteSubKeys($keyString) {
		$deletedSubKeys = false;
		
		$iterator = null;
		while ($subKeys = $this->redis->scan($iterator, $keyString.'*')) {
			foreach ($subKeys as $subKey) {
				$this->redis->delete($subKey);
				$deletedSubKeys = true;
			}
		}
		
		return $deletedSubKeys;
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
	
	/**
	 * @param array $options
	 * @param       $server
	 * @return \Redis
	 */
	protected function connectToSingleRedisServer(array $options, $server) {
		$redis = new \Redis();
		
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
		
		return $redis;
	}
	
	/**
	 * @param array $options
	 * @param array $servers
	 * @return \RedisArray
	 */
	protected function connectToMultipleRedisServers(array $options, array $servers) {
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
		
		return new \RedisArray($serverArray, $redisArrayOptions);
	}
}
