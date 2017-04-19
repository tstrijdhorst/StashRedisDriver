<?php

namespace ResponStashTest\Driver;

use PHPUnit\Framework\TestCase;
use ResponStash\Driver\Redis;
use Stash\Utilities;

class RedisTest extends TestCase {
	protected $redisServer = '192.168.33.11';
	protected $redisPort   = 6666;
	
	/** @var  Redis */
	private $redisDriver;
	/** @var  \Redis */
	private $redisClient;
	
	public function setUp() {
		parent::setUp();
		
		$this->redisDriver = new Redis(
			[
				'servers'        => [
					[$this->redisServer, $this->redisPort],
				],
				'normalize_keys' => false,
			]
		);
		
		$this->redisClient = new \Redis();
		$this->redisClient->connect($this->redisServer, $this->redisPort);
		$this->redisClient->flushDB();
	}
	
	public function testItDeletesNormalizedSubkeys() {
		$this->redisDriver = new Redis(
			[
				'servers'        => [
					[$this->redisServer, $this->redisPort],
				],
				'normalize_keys' => true,
			]
		);
		
		$this->testItDeletesSubkeys($normalization = true);
	}
	
	public function testItDeletesSubkeys($normalizeKeys = false) {
		$keyBase = ['cache', 'namespace', 'test', 'directory'];
		
		$this->redisDriver->storeData($keyBase, 'stackparent', null);
		$amountOfTestKeys = 5;
		//Insert initial data in a stacked structure
		for ($i = 0; $i < $amountOfTestKeys; $i++) {
			$key            = $keyBase;
			$testKeyIndexed = 'test'.$i;
			$key[]          = $testKeyIndexed;
			
			$this->redisDriver->storeData($key, 'stackChild', null);
			
			if ($normalizeKeys) {
				$key = Utilities::normalizeKeys($key);
			}
			$keyCheck = implode(':', $key);
			
			$this->assertNotFalse($this->redisClient->get($keyCheck));
		}
		
		//Delete the stackparent
		$this->redisDriver->clear($keyBase);
		
		$this->assertFalse($this->redisDriver->getData($keyBase), 'The stackparent should not exist after deletion');
		
		//Insert the second batch of data that should now have a new index
		for ($i = 0; $i < $amountOfTestKeys; $i++) {
			$key            = $keyBase;
			$testKeyIndexed = 'test'.$i;
			$key[]          = $testKeyIndexed;
			
			$this->redisDriver->storeData($key, 'testdata', null);
			
			$keyCheckOldIndex = $keyCheckNewIndex = $key;
			
			if ($normalizeKeys) {
				$keyCheckOldIndex = Utilities::normalizeKeys($key);
				$keyCheckNewIndex = Utilities::normalizeKeys($key);
			}
			
			$keyCheckStringOldIndex = implode(':', $keyCheckOldIndex);
			
			$keyCheckNewIndex[count($key) - 2] .= '_1';
			$keyCheckStringNewIndex            = implode(':', $keyCheckNewIndex);
			
			$this->assertFalse($this->redisClient->get($keyCheckStringOldIndex), 'initial keys should be gone');
			$this->assertNotFalse($this->redisClient->get($keyCheckStringNewIndex), 'second batch of keys should exist with index');
		}
	}
}