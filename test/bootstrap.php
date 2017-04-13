<?php

/*
 * This file is part of an alternative driver package and is not part of the Stash Package.
 * It is however derived from that code.
 */


define('TESTING', true);// this is basically used by the StashArray driver to decide if "isEnabled()" should return
// true, since the Array driver is not meant for production use, just testing. We should not
// use this anywhere else in the project since that would defeat the point of testing.
define('TESTING_DIRECTORY', __DIR__);
error_reporting(-1);

date_default_timezone_set('UTC');

$filename = __DIR__ .'/../vendor/autoload.php';

if (!file_exists($filename)) {
	echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~" . PHP_EOL;
	echo " You need to execute `composer install` before running the tests. " . PHP_EOL;
	echo "         Vendors are required for complete test execution.        " . PHP_EOL;
	echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~" . PHP_EOL . PHP_EOL;
	$filename = __DIR__ .'/../autoload.php';
}

$loader = require $filename;
