<?php

namespace ResponStashTest\Driver;

use PHPUnit\Framework\TestCase;
use ResponStash\Driver\Redis;

class RedisTest extends TestCase {
	protected $redisServer = '127.0.0.1';
	protected $redisPort   = 6379;
	
	/** @var  Redis */
	private $redisDriver;
	/** @var  \Redis */
	private $redisClient;
	
	public function setUp() {
		parent::setUp();
		
		$this->redisDriver = new Redis(
			[
				'servers' => [
					[$this->redisServer, $this->redisPort],
				],
			]
		);
		
		//Lets get the redis client so we can do some manual confirmation
		$redisProperty = (new \ReflectionClass($this->redisDriver))->getProperty('redis');
		$redisProperty->setAccessible(true);
		
		$this->redisClient = $redisProperty->getValue($this->redisDriver);
	}
	
	public function testItDeletesSubkeys() {
		//@todo fix more generic also for normalization
		//@todo clean redis after test
		$keyBase                   = ['cache', 'namespace', 'test', 'directory'];
		$keyBaseString             = 'cache:namespace:test:directory:';
		$keyBaseStringAfterReindex = 'cache:namespace:test:directory_1:';
		
		$this->redisDriver->storeData($keyBase, 'stackparent', null);
		$amountOfTestKeys = 5;
		//Insert initial data in a stacked structure
		for ($i = 0; $i < $amountOfTestKeys; $i++) {
			$key            = $keyBase;
			$testKeyIndexed = 'test'.$i;
			$key[]          = $testKeyIndexed;
			
			$this->redisDriver->storeData($key, 'stackChild', null);
			$this->assertNotFalse($this->redisClient->get($keyBaseString.$testKeyIndexed));
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
			$this->assertFalse($this->redisClient->get($keyBaseString.$testKeyIndexed), 'initial keys should be gone');
			$this->assertNotFalse($this->redisClient->get($keyBaseStringAfterReindex.$testKeyIndexed), 'second batch of keys should exist with index');
		}
		
		//@todo better cleanup
		//Delete the stackparent
		$this->redisDriver->clear($keyBase);
		$pathString = rtrim('pathdb:'.$keyBaseString, ':');
		$this->redisClient->del($pathString);
	}
}