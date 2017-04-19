<?php

namespace ResponStashTest\Driver;

use PHPUnit\Framework\TestCase;
use ResponStash\Driver\Redis;
use Stash\Exception\InvalidArgumentException;
use Stash\Utilities;

class RedisTest extends TestCase {
	protected $redisServer = '192.168.33.11';
	protected $redisPort   = 6666;
	
	/** @var  \Redis */
	private $redisClient;
	
	public function setUp() {
		parent::setUp();
		
		$this->redisClient = new \Redis();
		$this->redisClient->connect($this->redisServer, $this->redisPort);
		$this->redisClient->flushDB();
	}
	
	public function testItDeletesUnnormalizedSubkeys() {
		$this->deleteSubkeysTest($normalizeKeys = false);
	}
	
	public function testItDeletedNormalizedSubkeys() {
		$this->deleteSubkeysTest($normalizeKeys = true);
	}
	
	public function testItCannotUseReservedCharactersIfUnnormalized() {
		$redisDriver = $this->getDriverInstance($normalizeKeys = false);
		
		$expectedException = null;
		try {
			$redisDriver->storeData(['cache', 'namespace', 'illegalkey:'], ['data'], null);
		}
		catch(InvalidArgumentException $e) {
			$expectedException = $e;
		}
		
		$this->assertInstanceOf(InvalidArgumentException::class, $expectedException);
		$this->assertEquals('You cannot use `:` or `_` in keys if key_normalization is off.', $expectedException->getMessage());
		
		$expectedException = null;
		try {
			$redisDriver->storeData(['cache', 'namespace', 'illegalkey_'], ['data'], null);
		}
		catch(InvalidArgumentException $e) {
			$expectedException = $e;
		}
		
		$this->assertInstanceOf(InvalidArgumentException::class, $expectedException);
		$this->assertEquals('You cannot use `:` or `_` in keys if key_normalization is off.', $expectedException->getMessage());
	}
	
	public function testItIncreasedTheIndexAfterStackParentDeletion() {
		$keyBase = ['cache', 'namespace', 'test', 'directory'];
		$redisDriver = $this->getDriverInstance($normalizeKeys = false);
		
		$pathDbProperty = (new \ReflectionClass($redisDriver))->getProperty('pathPrefix');
		$pathDbProperty->setAccessible(true);
		$pathDb = $pathDbProperty->getValue($redisDriver);
		
		$testKey = $keyBase;
		$testKey[] = 'key1';
		$redisDriver->storeData($testKey, ['testData'], null);
		$this->assertNotFalse($this->redisClient->get('cache:namespace:test:directory:key1'));
		
		$redisDriver->clear($keyBase);
		$this->assertFalse($this->redisClient->get('cache:namespace:test:directory:key1'));
		$this->assertEquals(1, $this->redisClient->get($pathDb.'cache:namespace:test:directory'));
		
		$redisDriver->storeData($testKey,['testData'], null);
		$this->assertNotFalse($this->redisClient->get('cache:namespace:test:directory_1:key1'));
		
		$redisDriver->clear($keyBase);
		$this->assertFalse($this->redisClient->get('cache:namespace:test:directory_1:key1'));
		$this->assertEquals(2, $this->redisClient->get($pathDb.'cache:namespace:test:directory'));
	}
	
	public function testItDoesNotIncreaseAnIndexAfterLeafDeletion() {
		$keyBase = ['cache', 'namespace', 'test', 'directory'];
		$redisDriver = $this->getDriverInstance($normalizeKeys = false);
		
		$pathDbProperty = (new \ReflectionClass($redisDriver))->getProperty('pathPrefix');
		$pathDbProperty->setAccessible(true);
		$pathDb = $pathDbProperty->getValue($redisDriver);
		
		$redisDriver->storeData($keyBase, ['testData'], null);
		$this->assertNotFalse($this->redisClient->get('cache:namespace:test:directory'));
		
		$redisDriver->clear($keyBase);
		$this->assertFalse($this->redisClient->get($pathDb.'cache:namespace:test:directory'));
	}
	
	/**
	 * @param bool $normalizeKeys
	 * @return Redis
	 */
	protected function getDriverInstance($normalizeKeys = true) {
		return new Redis(
			[
				'servers'        => [
					[$this->redisServer, $this->redisPort],
				],
				'normalize_keys' => $normalizeKeys,
			]
		);
	}
	
	private function deleteSubkeysTest($normalizeKeys = true) {
		$redisDriver = $this->getDriverInstance($normalizeKeys);
		
		$keyBase = ['cache', 'namespace', 'test', 'directory'];
		
		$redisDriver->storeData($keyBase, 'stackparent', null);
		$amountOfTestKeys = 5;
		//Insert initial data in a stacked structure
		for ($i = 0; $i < $amountOfTestKeys; $i++) {
			$key            = $keyBase;
			$testKeyIndexed = 'test'.$i;
			$key[]          = $testKeyIndexed;
			
			$redisDriver->storeData($key, 'stackChild', null);
			
			if ($normalizeKeys) {
				$key = Utilities::normalizeKeys($key);
			}
			$keyCheck = implode(':', $key);
			
			$this->assertNotFalse($this->redisClient->get($keyCheck));
		}
		
		//Delete the stackparent
		$redisDriver->clear($keyBase);
		
		$this->assertFalse($redisDriver->getData($keyBase), 'The stackparent should not exist after deletion');
		
		//Insert the second batch of data that should now have a new index
		for ($i = 0; $i < $amountOfTestKeys; $i++) {
			$key            = $keyBase;
			$testKeyIndexed = 'test'.$i;
			$key[]          = $testKeyIndexed;
			
			$redisDriver->storeData($key, 'testdata', null);
			
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