<?php

namespace App\Config;

use Dotenv\Dotenv;
use R;

class Database
{
    /** @var bool */
    private static $initialized = false;

    /**
     * Initialize the database connection using environment variables.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Enforce Africa/Lagos timezone
        date_default_timezone_set('Africa/Lagos');

        // Load environment variables if available
        if (class_exists(Dotenv::class)) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $dbhost = $_ENV['DB_HOST'] ?? $_ENV['dbhost'] ?? 'localhost';
        $dbuser = $_ENV['DB_USER'] ?? $_ENV['dbuser'] ?? 'root';
        $dbpass = $_ENV['DB_PASS'] ?? $_ENV['dbpass'] ?? '';
        $dbname = $_ENV['DB_NAME'] ?? $_ENV['dbname'] ?? 'airx';

        if (!R::testConnection()) {
            R::setup("mysql:host={$dbhost};dbname={$dbname}", $dbuser, $dbpass);
        }

        try {
            R::exec("SET time_zone = '+01:00'");
        } catch (\Exception $e) {
            // Ignore if mysql time_zone table is unpopulated
        }

        // Action A3: Add new columns before freezing the database (Code A3)
        try {
            if (R::testConnection()) {
                $predCols = R::inspect('predictions');
                if (!empty($predCols)) {
                    if (!in_array('method', $predCols, true)) {
                        R::exec("ALTER TABLE `predictions` ADD COLUMN `method` VARCHAR(32) NULL");
                    }
                    if (!in_array('accuracy', $predCols, true)) {
                        R::exec("ALTER TABLE `predictions` ADD COLUMN `accuracy` DECIMAL(6,2) NULL");
                    }
                }
                $suppCols = R::inspect('support');
                if (!empty($suppCols)) {
                    if (!in_array('created_at', $suppCols, true)) {
                        R::exec("ALTER TABLE `support` ADD COLUMN `created_at` DATETIME NULL");
                    }
                    if (!in_array('updated_at', $suppCols, true)) {
                        R::exec("ALTER TABLE `support` ADD COLUMN `updated_at` DATETIME NULL");
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Schema migration notice: " . $e->getMessage());
        }

        // Freeze RedBean schema updates in production
        $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');
        if ($appEnv === 'production') {
            R::freeze(true);
        }

        self::$initialized = true;
    }

    /**
     * Get the configured external/main database name (default: lifebank_plus).
     */
    public static function getMainDbName(): string
    {
        return $_ENV['MAIN_DB_NAME'] ?? $_ENV['LIFEBANK_DB_NAME'] ?? 'lifebank_plus';
    }

    /**
     * Safely close the database connection.
     */
    public static function close(): void
    {
        R::close();
    }
}
