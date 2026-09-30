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
                $hasColumn = function(array $cols, string $colName): bool {
                    return array_key_exists($colName, $cols) || in_array($colName, $cols, true) || in_array($colName, array_keys($cols), true);
                };

                $predCols = R::inspect('predictions') ?: [];
                if (!empty($predCols)) {
                    if (!$hasColumn($predCols, 'method')) {
                        try {
                            R::exec("ALTER TABLE `predictions` ADD COLUMN `method` VARCHAR(32) NULL");
                        } catch (\Exception $ex) {
                            // Column already exists
                        }
                    }
                    if (!$hasColumn($predCols, 'accuracy')) {
                        try {
                            R::exec("ALTER TABLE `predictions` ADD COLUMN `accuracy` DECIMAL(6,2) NULL");
                        } catch (\Exception $ex) {
                            // Column already exists
                        }
                    }
                }
                $suppCols = R::inspect('support') ?: [];
                if (!empty($suppCols)) {
                    if (!$hasColumn($suppCols, 'created_at')) {
                        try {
                            R::exec("ALTER TABLE `support` ADD COLUMN `created_at` DATETIME NULL");
                        } catch (\Exception $ex) {
                            // Column already exists
                        }
                    }
                    if (!$hasColumn($suppCols, 'updated_at')) {
                        try {
                            R::exec("ALTER TABLE `support` ADD COLUMN `updated_at` DATETIME NULL");
                        } catch (\Exception $ex) {
                            // Column already exists
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false && strpos($e->getMessage(), '1060') === false) {
                error_log("Schema migration notice: " . $e->getMessage());
            }
        }

        // Freeze RedBean schema updates in production
        $appEnv = strtolower($_ENV['APP_ENV'] ?? 'development');
        if ($appEnv === 'production') {
            R::freeze(true);
        }

        self::$initialized = true;
    }

    /**
     * Get the configured external/main database name.
     * Defaults to the current active DB if MAIN_DB_NAME is not set,
     * allowing standalone single-database open-source deployments.
     */
    public static function getMainDbName(): string
    {
        return $_ENV['MAIN_DB_NAME'] 
            ?? $_ENV['LIFEBANK_DB_NAME'] 
            ?? $_ENV['DB_NAME'] 
            ?? $_ENV['dbname'] 
            ?? 'airx';
    }

    /**
     * Safely close the database connection.
     */
    public static function close(): void
    {
        R::close();
    }
}
