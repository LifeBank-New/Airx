<?php

require_once __DIR__ . '/rb.php';

// Ensure vendor autoload is present for Dotenv if not loaded yet
if (!class_exists('Dotenv\Dotenv')) {
	$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
	if (file_exists($autoloadPath)) {
		require_once $autoloadPath;
	}
}

// Delegate to central Database configuration
if (class_exists('App\Config\Database')) {
	\App\Config\Database::init();
} else {
	if (class_exists('Dotenv\Dotenv')) {
		$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
		$dotenv->safeLoad();
	}
	date_default_timezone_set('Africa/Lagos');
	$dbhost = $_ENV['DB_HOST'] ?? $_ENV['dbhost'] ?? 'localhost';
	$dbuser = $_ENV['DB_USER'] ?? $_ENV['dbuser'] ?? 'root';
	$dbpass = $_ENV['DB_PASS'] ?? $_ENV['dbpass'] ?? '';
	$dbname = $_ENV['DB_NAME'] ?? $_ENV['dbname'] ?? 'airx';

	if (!R::testConnection()) {
		R::setup('mysql:host=' . $dbhost . ';dbname=' . $dbname, $dbuser, $dbpass);
	}
	$appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');
	if ($appEnv === 'production') {
		R::freeze(true);
	}
}