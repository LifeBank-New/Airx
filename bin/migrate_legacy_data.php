#!/usr/bin/env php
<?php
/**
 * AirX - Standalone Legacy Data Migration & Table Cleanup Script
 *
 * Issue R3: Migrates legacy patient records from `data` table to `facility_monthly_usage`
 * in aggregated monthly buckets, verifies 100% data integrity, and drops `data` table.
 * @license MIT
 * @copyright 2026 LifeBank Nigeria (developer@lifebank.ng)
 *
 * Usage:
 *   php bin/migrate_legacy_data.php [options]
 *
 * Options:
 *   --host=<host>        Database host (default: DB_HOST env or localhost)
 *   --port=<port>        Database port (default: DB_PORT env or 3306)
 *   --user=<user>        Database username (default: DB_USER env or root)
 *   --pass=<pass>        Database password (default: DB_PASS env or empty)
 *   --db=<name>          Database name (default: DB_NAME env or airx)
 *   --dry-run            Simulate migration and verification without dropping `data` table
 *   -h, --help           Show this help message
 */

declare(strict_types=1);

// Load composer autoloader and Dotenv if available
$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/vendor/autoload.php')) {
    require_once $rootDir . '/vendor/autoload.php';
}

if (class_exists(\Dotenv\Dotenv::class)) {
    foreach ([$rootDir, dirname($rootDir)] as $envDir) {
        if (file_exists($envDir . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable($envDir);
            $dotenv->safeLoad();
        }
    }
}

// Parse command line arguments
$options = getopt('h', ['host:', 'port:', 'user:', 'pass:', 'password:', 'db:', 'dbname:', 'dry-run', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP
AirX Legacy Data Migration CLI Tool

Usage:
  php bin/migrate_legacy_data.php [options]

Options:
  --host=<host>        Database host (default: DB_HOST env or localhost)
  --port=<port>        Database port (default: DB_PORT env or 3306)
  --user=<user>        Database username (default: DB_USER env or root)
  --pass=<pass>        Database password (default: DB_PASS env or empty)
  --db=<name>          Database name (default: DB_NAME env or airx)
  --dry-run            Run aggregation and verification without dropping `data` table
  -h, --help           Display this help text

HELP;
    exit(0);
}

$dbHost = $options['host'] ?? $_ENV['DB_HOST'] ?? $_ENV['dbhost'] ?? 'localhost';
$dbPort = (int)($options['port'] ?? $_ENV['DB_PORT'] ?? $_ENV['dbport'] ?? 3306);
$dbUser = $options['user'] ?? $_ENV['DB_USER'] ?? $_ENV['dbuser'] ?? 'root';
$dbPass = $options['pass'] ?? $options['password'] ?? $_ENV['DB_PASS'] ?? $_ENV['dbpass'] ?? '';
$dbName = $options['db'] ?? $options['dbname'] ?? $_ENV['DB_NAME'] ?? $_ENV['dbname'] ?? 'airx';
$dryRun = isset($options['dry-run']);

echo "========================================================\n";
echo "AirX Legacy Data Migration & Sensitive Table Cleanup\n";
echo "========================================================\n";
echo "Target Database: {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n";
echo "Dry Run Mode:    " . ($dryRun ? "YES (table will NOT be dropped)" : "NO") . "\n";
echo "Started at:      " . date('Y-m-d H:i:s') . "\n";
echo "--------------------------------------------------------\n";

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    echo "[OK] Connected to database successfully.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "[ERROR] Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. Ensure facility_monthly_usage table exists
echo "[STEP 1] Ensuring target table `facility_monthly_usage` exists...\n";
$createTargetTableSql = "CREATE TABLE IF NOT EXISTS `facility_monthly_usage` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `hospital_id` INT NOT NULL,
    `hospitalID` INT NOT NULL,
    `period` VARCHAR(7) NOT NULL,
    `oxygen_used_m3` DECIMAL(10,2) NOT NULL,
    `created_at` DATETIME NULL,
    KEY `idx_hospital_id` (`hospital_id`),
    KEY `idx_hospitalID` (`hospitalID`),
    KEY `idx_period` (`period`),
    UNIQUE KEY `uniq_hospital_period` (`hospital_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$pdo->exec($createTargetTableSql);
echo "[OK] Target table `facility_monthly_usage` verified.\n";

// 2. Check if legacy table `data` exists
echo "[STEP 2] Checking for legacy table `data`...\n";
$stmtCheck = $pdo->prepare("
    SELECT COUNT(*) 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = 'data'
");
$stmtCheck->execute(['dbname' => $dbName]);
$tableExists = (int)$stmtCheck->fetchColumn() > 0;

if (!$tableExists) {
    echo "[INFO] Table `data` does not exist in database `{$dbName}`.\n";
    echo "[INFO] Legacy data migration has already been completed and table `data` is already removed.\n";
    echo "========================================================\n";
    echo "Status: COMPLETE (No action needed)\n";
    echo "========================================================\n";
    exit(0);
}

// 3. Inspect legacy records in `data`
echo "[STEP 3] Inspecting legacy table `data`...\n";
$rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `data`")->fetchColumn();
echo "[INFO] Found {$rowCount} rows in `data` table.\n";

if ($rowCount === 0) {
    echo "[INFO] Table `data` contains 0 rows. Proceeding to drop empty table...\n";
    if (!$dryRun) {
        $pdo->exec("DROP TABLE `data`");
        echo "[SUCCESS] Table `data` dropped successfully.\n";
    } else {
        echo "[DRY-RUN] Skipped DROP TABLE `data`.\n";
    }
    exit(0);
}

$legacyTotalSum = (float)$pdo->query("SELECT SUM(estimate_need) FROM `data`")->fetchColumn();
echo sprintf("[INFO] Total legacy oxygen estimate: %.2f m3\n", $legacyTotalSum);

// 4. Fetch all records and aggregate into monthly buckets
echo "[STEP 4] Fetching all records and normalizing dates into (hospital_id, period) groups...\n";
$stmtRows = $pdo->query("SELECT id, hospitalID, date_used, created_at, estimate_need FROM `data`");
$allRows = $stmtRows->fetchAll();

$groups = [];
$totalRowsProcessed = 0;
$totalEstimateAccumulated = 0.0;

foreach ($allRows as $r) {
    $totalRowsProcessed++;
    $hosId = (int)$r['hospitalID'];
    $dateStr = trim((string)($r['date_used'] ?? ''));
    $period = null;

    // Support ISO YYYY-MM-DD or YYYY-MM
    if (preg_match('/^(\d{4})-(\d{2})/', $dateStr, $matches)) {
        $period = $matches[1] . '-' . $matches[2];
    }
    // Support DD/MM/YYYY or D/M/YYYY
    elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $dateStr, $matches)) {
        $period = sprintf('%04d-%02d', (int)$matches[3], (int)$matches[2]);
    }
    // Fallback to created_at if date_used is missing or zero-date
    elseif (!empty($r['created_at']) && preg_match('/^(\d{4})-(\d{2})/', trim((string)$r['created_at']), $matches)) {
        $period = $matches[1] . '-' . $matches[2];
    }

    if (!$period || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        throw new RuntimeException("Unable to determine valid period for data row ID {$r['id']} (hospitalID: {$hosId}, date_used: '{$dateStr}', created_at: '{$r['created_at']}')");
    }

    $need = (float)$r['estimate_need'];
    $totalEstimateAccumulated += $need;
    $groupKey = "{$hosId}:{$period}";

    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            'hospital_id'    => $hosId,
            'hospitalID'     => $hosId,
            'period'         => $period,
            'oxygen_used_m3' => 0.0,
            'created_at'     => !empty($r['created_at']) ? $r['created_at'] : date('Y-m-d H:i:s'),
            'record_count'   => 0
        ];
    }

    $groups[$groupKey]['oxygen_used_m3'] += $need;
    $groups[$groupKey]['record_count']++;

    if (!empty($r['created_at']) && $r['created_at'] < $groups[$groupKey]['created_at']) {
        $groups[$groupKey]['created_at'] = $r['created_at'];
    }
}

echo sprintf("[INFO] Processed %d records into %d aggregated monthly groups.\n", $totalRowsProcessed, count($groups));
echo sprintf("[INFO] Accumulated group total: %.2f m3 (Expected: %.2f m3)\n", $totalEstimateAccumulated, $legacyTotalSum);

if (abs($totalEstimateAccumulated - $legacyTotalSum) > 0.01) {
    throw new RuntimeException(sprintf("Integrity check failed: accumulated sum (%.2f) does not match legacy total sum (%.2f)", $totalEstimateAccumulated, $legacyTotalSum));
}

// 5. Upsert aggregated groups into facility_monthly_usage
echo "[STEP 5] Upserting groups into `facility_monthly_usage`...\n";
$upsertSql = "INSERT INTO `facility_monthly_usage` 
    (`hospital_id`, `hospitalID`, `period`, `oxygen_used_m3`, `created_at`)
VALUES 
    (:hospital_id, :hospitalID, :period, :oxygen_used_m3, :created_at)
ON DUPLICATE KEY UPDATE 
    `oxygen_used_m3` = VALUES(`oxygen_used_m3`),
    `hospitalID` = VALUES(`hospitalID`)";

$stmtUpsert = $pdo->prepare($upsertSql);

foreach ($groups as $key => $g) {
    $stmtUpsert->execute([
        'hospital_id'    => $g['hospital_id'],
        'hospitalID'     => $g['hospitalID'],
        'period'         => $g['period'],
        'oxygen_used_m3' => round($g['oxygen_used_m3'], 2),
        'created_at'     => $g['created_at'],
    ]);
}
echo "[OK] All " . count($groups) . " groups successfully upserted.\n";

// 6. Verification: Check that every aggregated group exists in facility_monthly_usage with exact values
echo "[STEP 6] Verifying records in `facility_monthly_usage`...\n";
$stmtVerify = $pdo->prepare("
    SELECT oxygen_used_m3 
    FROM `facility_monthly_usage` 
    WHERE hospital_id = :hospital_id AND period = :period
");

$verifiedCount = 0;
$verifiedSum = 0.0;

foreach ($groups as $key => $g) {
    $stmtVerify->execute([
        'hospital_id' => $g['hospital_id'],
        'period'      => $g['period']
    ]);
    $storedValue = $stmtVerify->fetchColumn();

    if ($storedValue === false) {
        throw new RuntimeException("Verification failed: Group {$key} not found in `facility_monthly_usage`.");
    }

    $expectedVal = round($g['oxygen_used_m3'], 2);
    $actualVal = round((float)$storedValue, 2);

    if (abs($expectedVal - $actualVal) > 0.01) {
        throw new RuntimeException("Verification failed for group {$key}: Expected {$expectedVal} m3, found {$actualVal} m3 in `facility_monthly_usage`.");
    }

    $verifiedCount++;
    $verifiedSum += $actualVal;
}

echo sprintf("[OK] Verified %d groups in `facility_monthly_usage`. Total verified volume: %.2f m3\n", $verifiedCount, $verifiedSum);

if (abs($verifiedSum - $legacyTotalSum) > 0.05) {
    throw new RuntimeException(sprintf("Verification volume mismatch: Verified %.2f m3 vs Legacy %.2f m3", $verifiedSum, $legacyTotalSum));
}

// 7. Drop legacy `data` table
echo "[STEP 7] Safely dropping legacy `data` table...\n";
if ($dryRun) {
    echo "[DRY-RUN] DRY RUN enabled: `DROP TABLE data` skipped.\n";
} else {
    $pdo->exec("DROP TABLE `data`");
    echo "[OK] `DROP TABLE data` executed.\n";

    // 8. Explicit confirmation with SHOW TABLES
    $stmtShow = $pdo->query("SHOW TABLES LIKE 'data'");
    $tablesFound = $stmtShow->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($tablesFound)) {
        throw new RuntimeException("Table `data` is still present after DROP TABLE statement!");
    }
    echo "[SUCCESS] Verified with SHOW TABLES: table `data` is completely removed.\n";
}

echo "--------------------------------------------------------\n";
echo "Summary of Completed Actions:\n";
echo " - Migrated legacy records:   {$totalRowsProcessed} patient records\n";
echo " - Aggregated monthly groups: {$verifiedCount} groups\n";
echo " - Total oxygen volume:       {$verifiedSum} m3\n";
echo " - Legacy table `data`:       DROPPED\n";
echo "========================================================\n";
echo "MIGRATION COMPLETED SUCCESSFULLY\n";
echo "========================================================\n";
exit(0);
