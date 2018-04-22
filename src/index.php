<?php

use Comhon\Object\Config\Config;

require_once 'vendor/autoload.php';

$base_path = '/home/jean-philippe/ReposGit/callipolis/src/';

spl_autoload_register(function ($class) {
	include_once __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
});

Config::setLoadPath("./src/config/config.json");
