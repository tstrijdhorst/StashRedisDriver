<?php

namespace ResponStashTest\Driver;

use PHPUnit\Framework\TestCase;
use ResponStash\Driver\Redis;

class RedisTest extends TestCase {
	public function testRedisDriver() {
		$driver = new Redis();
	}
}