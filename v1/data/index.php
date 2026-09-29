<?php 
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\AuthService;
use App\Config\Database;

require '../../vendor/autoload.php';
require '../../include/dbsol/conn.php';

$authService = new AuthService();

// Action B2: enforce strong AUTH_TOKEN
$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? $_SERVER['AUTH_TOKEN'] ?? $_SERVER['authtoken'] ?? (getenv('AUTH_TOKEN') ?: getenv('authtoken')) ?: '';
if (empty($authToken) && class_exists('Dotenv\Dotenv')) {
	foreach ([dirname(__DIR__, 2), dirname(__DIR__, 3), dirname(__DIR__)] as $dir) {
		if (file_exists($dir . '/.env')) {
			$dotenv = Dotenv\Dotenv::createImmutable($dir);
			$dotenv->safeLoad();
		}
	}
	$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? $_SERVER['AUTH_TOKEN'] ?? $_SERVER['authtoken'] ?? (getenv('AUTH_TOKEN') ?: getenv('authtoken')) ?: '';
}
if (strlen($authToken) < 24 || $authToken === 'test') {
	throw new RuntimeException('AUTH_TOKEN must be set to a long random value (minimum 24 characters and not "test")');
}

// Action A1: Run database migration if legacy data table exists
migrateDataToFacilityMonthlyUsage();

$app = new \Slim\App;

// Action B6: Mask internal exception details in JWT middleware
$authMiddleware = function ($request, $response, $next) use ($authService) {
	$authHeader = $request->getHeaderLine('Authorization');

	if (!$authHeader || !preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
		$data = [
			'status'  => 'error',
			'code'    => 'AUTH_HEADER_MISSING',
			'message' => 'Missing or invalid authorization token'
		];

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($data, JSON_UNESCAPED_SLASHES));
	}

	$jwt = $matches[1];

	try {
		$decoded = $authService->decodeToken($jwt);
		$request = $request->withAttribute('user', $decoded);
		return $next($request, $response);
	} catch (\Firebase\JWT\ExpiredException $e) {
		error_log("JWT Expired: " . $e->getMessage());
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_EXPIRED',
				'message' => 'Token has expired'
			], JSON_UNESCAPED_SLASHES));
	} catch (\Firebase\JWT\SignatureInvalidException $e) {
		error_log("JWT Invalid Signature: " . $e->getMessage());
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_INVALID_SIGNATURE',
				'message' => 'Invalid token signature'
			], JSON_UNESCAPED_SLASHES));
	} catch (Exception $e) {
		error_log("JWT Error: " . $e->getMessage());
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_INVALID',
				'message' => 'Invalid token'
			], JSON_UNESCAPED_SLASHES));
	}
};

$app->get('/', function (Request $request, Response $response, array $args) use ($authToken) {
	$authorization_header = $request->getHeader("Authorization");

	if (empty($authorization_header) || !hash_equals((string)$authToken, (string)$authorization_header[0])) {
		$return = array('status' => 'false', 'Description' => 'Data Processing.', 'Message' => 'Header is missing or invalid', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return = array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'method allowed,post', 'data' => null);

	return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
});

$app->post('/add', function (Request $request, Response $response) use ($authToken, $authService) {
	$authHeader = $request->getHeaderLine("Authorization");
	$user = null;

	if ($authHeader && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
		try {
			$user = $authService->decodeToken($matches[1]);
		} catch (Exception $e) {
			// Fall through
		}
	}

	$tokenToCheck = $authHeader;
	if (preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
		$tokenToCheck = $matches[1];
	}

	if (empty($user) && (empty($tokenToCheck) || !hash_equals((string)$authToken, (string)$tokenToCheck))) {
		$return = array('status' => 'false', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'Header is missing or invalid', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	// Action A1: Reject any patient-level attributes
	$patientFields = ['gender', 'age', 'conditions', 'underlying_conditions', 'treatment', 'treatrment', 'flow_rate', 'estimate_need'];
	foreach ($patientFields as $field) {
		$val = $request->getParam($field);
		if ($val !== null && $val !== '') {
			return $response->withStatus(422)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode([
					'status'  => 'error',
					'code'    => 'PATIENT_DATA_NOT_PERMITTED',
					'message' => 'AirX stores facility-level monthly totals only, and rejects any record containing patient attributes.'
				]));
		}
	}

	// Action B1: Extract hospital ID from JWT ref_id when present
	$hospitalID = !empty($user['ref_id'])
		? (int)$user['ref_id']                          // logged-in hospital: always its own ID
		: (int)($request->getParam('hospitalID') ?? $request->getParam('hospitalid')); // shared-token callers only

	if ($hospitalID <= 0) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Valid hospital ID is required']));
	}

	// Facility monthly usage fields
	$period = trim((string)($request->getParam('period') ?? $request->getParam('month') ?? $request->getParam('date_used') ?? ''));
	if (preg_match('/^(\d{4}-\d{2})(-\d{2})?$/', $period, $m)) {
		$period = $m[1];
	} else {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Valid period format (YYYY-MM) is required']));
	}

	$oxygenUsed = $request->getParam('oxygen_used_m3') ?? $request->getParam('total_cubic_meters') ?? $request->getParam('usage');
	if ($oxygenUsed === null || !is_numeric($oxygenUsed) || (float)$oxygenUsed < 0) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Valid non-negative oxygen_used_m3 is required']));
	}
	$oxygenUsed = (float)$oxygenUsed;

	try {
		try {
			$sql = "INSERT INTO `facility_monthly_usage` (`hospital_id`, `hospitalID`, `period`, `oxygen_used_m3`, `created_at`) 
					VALUES (?, ?, ?, ?, NOW()) 
					ON DUPLICATE KEY UPDATE `oxygen_used_m3` = VALUES(`oxygen_used_m3`)";
			R::exec($sql, [$hospitalID, $hospitalID, $period, $oxygenUsed]);
		} catch (Exception $colEx) {
			$sql = "INSERT INTO `facility_monthly_usage` (`hospital_id`, `period`, `oxygen_used_m3`, `created_at`) 
					VALUES (?, ?, ?, NOW()) 
					ON DUPLICATE KEY UPDATE `oxygen_used_m3` = VALUES(`oxygen_used_m3`)";
			R::exec($sql, [$hospitalID, $period, $oxygenUsed]);
		}
		$id = R::getInsertID();

		$return = array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'Facility monthly usage data was successfully processed', 'data' => $id);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Facility data insert error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
});

$app->get('/hospital', function (Request $request, Response $response) {
	try {
		$user = $request->getAttribute('user');
		$refid = (int)$user['ref_id'];

		$mainDb = Database::getMainDbName();

		// Action A1: Point to facility_monthly_usage
		$allUploadedData = R::findAll('facility_monthly_usage', 'hospital_id = ? ORDER BY period DESC', [$refid]);
		$month = R::getAll("SELECT SUM(oxygen_used_m3) as estimate_need, MIN(DATE_FORMAT(CONCAT(period, '-01'), '%M')) AS month_name, MIN(DATE_FORMAT(CONCAT(period, '-01'), '%Y')) AS month_year FROM `facility_monthly_usage` WHERE hospital_id = ? GROUP BY period ORDER BY period DESC", [$refid]);

		// Action A4: Wrap %Y and %M in MIN() and order by MIN(o.tym) DESC for ONLY_FULL_GROUP_BY compliance
		$lastSixMonthsUsage = R::getAll("SELECT MIN(DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y')) AS month_year, MIN(DATE_FORMAT(FROM_UNIXTIME(o.tym), '%M')) AS month_name, SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters FROM `{$mainDb}`.oxygen_order AS o LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id WHERE FROM_UNIXTIME(o.tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.order_by = ? GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') ORDER BY MIN(o.tym) DESC", [$refid]);

		// Action A2: Remove hospitalID reference from predictions query
		$lastSixMonthPredict = R::getAll("SELECT p.predictions AS total_cubic_meters, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%Y') AS month_year, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%M') AS month_name FROM `predictions` p INNER JOIN (SELECT MAX(tym) AS max_tym FROM `predictions` WHERE hospital_id = ? AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(FROM_UNIXTIME(tym), '%Y-%m')) latest ON p.tym = latest.max_tym WHERE p.hospital_id = ? ORDER BY p.tym DESC", [$refid, $refid]);

		$data = ['chart' => ['predicted' => $lastSixMonthPredict, 'actual' => $lastSixMonthsUsage], 'data' => array_values($allUploadedData), 'month' => $month];

		$return = array('status' => 'success', 'Description' => 'hospital informations endpoints', 'data' => $data);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Hospital data query error: " . $e->getMessage());
		$response->getBody()->write(json_encode([
			'status'  => 'error',
			'message' => 'An internal server error occurred'
		]));
		return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
	} finally {
		R::close();
	}
})->add($authMiddleware);

$app->post('/hospital/add', function (Request $request, Response $response) {
	try {
		$user = $request->getAttribute('user');

		// Action B1: Extract hospital from login token
		$refid = !empty($user['ref_id'])
			? (int)$user['ref_id']
			: (int)($request->getParam('hospitalID') ?? $request->getParam('hospitalid'));

		if ($refid <= 0) {
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
				->write(json_encode(['status' => 'error', 'message' => 'Valid hospital ID is required']));
		}

		$uploadedFiles = $request->getUploadedFiles();
		$records = [];
		$headers = [];

		if (isset($uploadedFiles['file'])) {
			$file = $uploadedFiles['file'];

			if ($file->getError() === UPLOAD_ERR_OK) {
				$filename = $file->getClientFilename();
				$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

				if ($ext !== 'csv') {
					throw new Exception("Unsupported file type: only CSV files are allowed");
				}

				$filePath = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.csv';
				$file->moveTo($filePath);

				$parsed = parseCsvFile($filePath);
				@unlink($filePath);

				$headers = $parsed['headers'];
				$records = $parsed['rows'];
			} else {
				throw new Exception('File upload failed.');
			}
		} else {
			$body = $request->getParsedBody();
			if (isset($body['templateData']) && is_array($body['templateData'])) {
				$records = $body['templateData'];
				if (!empty($records) && is_array($records[0])) {
					$headers = array_keys($records[0]);
				}
			} else {
				throw new Exception('Missing or invalid "templateData" in JSON body');
			}
		}

		if (empty($records)) {
			throw new Exception('No valid records found.');
		}

		// Action A1: Detect and reject patient attributes in CSV headers or records
		$patientIndicators = ['gender', 'age', 'conditions', 'underlying_conditions', 'treatment', 'treatrment', 'flow_rate', 'estimate_need'];
		foreach ($headers as $h) {
			$cleanH = strtolower(trim((string)$h));
			if (in_array($cleanH, $patientIndicators, true)) {
				return $response->withStatus(422)->withHeader('Content-Type', 'application/json')
					->write(json_encode([
						'status'  => 'error',
						'code'    => 'PATIENT_DATA_NOT_PERMITTED',
						'message' => 'AirX stores facility-level monthly totals only, and rejects any record containing patient attributes.'
					]));
			}
		}

		foreach ($records as $row) {
			foreach ($patientIndicators as $field) {
				if (isset($row[$field]) && $row[$field] !== '') {
					return $response->withStatus(422)->withHeader('Content-Type', 'application/json')
						->write(json_encode([
							'status'  => 'error',
							'code'    => 'PATIENT_DATA_NOT_PERMITTED',
							'message' => 'AirX stores facility-level monthly totals only, and rejects any record containing patient attributes.'
						]));
				}
			}
		}

		$recordsAdded = 0;
		$errors = [];

		foreach ($records as $i => $row) {
			// Extract period (YYYY-MM)
			$rawPeriod = $row['period'] ?? $row['month'] ?? $row['date'] ?? $row['date_used'] ?? null;
			$period = null;
			if ($rawPeriod && preg_match('/^(\d{4}-\d{2})(-\d{2})?$/', trim((string)$rawPeriod), $m)) {
				$period = $m[1];
			}

			// Extract oxygen volume
			$rawOxygen = $row['oxygen_used_m3'] ?? $row['total_cubic_meters'] ?? $row['oxygen_used'] ?? $row['volume'] ?? $row['usage'] ?? null;

			if ($period === null || $rawOxygen === null || !is_numeric($rawOxygen) || (float)$rawOxygen < 0) {
				$errors[] = [
					'index' => $i,
					'message' => 'Missing or invalid fields. Expected period (YYYY-MM) and non-negative oxygen_used_m3.'
				];
				continue;
			}

			$oxygenUsed = (float)$rawOxygen;

			try {
				$sql = "INSERT INTO `facility_monthly_usage`
						(`hospital_id`, `hospitalID`, `period`, `oxygen_used_m3`, `created_at`)
						VALUES (?, ?, ?, ?, NOW())
						ON DUPLICATE KEY UPDATE `oxygen_used_m3` = VALUES(`oxygen_used_m3`)";
				R::exec($sql, [$refid, $refid, $period, $oxygenUsed]);
			} catch (Exception $colEx) {
				$sql = "INSERT INTO `facility_monthly_usage`
						(`hospital_id`, `period`, `oxygen_used_m3`, `created_at`)
						VALUES (?, ?, ?, NOW())
						ON DUPLICATE KEY UPDATE `oxygen_used_m3` = VALUES(`oxygen_used_m3`)";
				R::exec($sql, [$refid, $period, $oxygenUsed]);
			}
			$recordsAdded++;
		}

		$response->getBody()->write(json_encode([
			'status' => 'success',
			'description' => 'Records added successfully',
			'data' => [
				'records_added'  => $recordsAdded,
				'records_failed' => count($errors),
				'errors'         => $errors
			]
		], JSON_PRETTY_PRINT));

		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

	} catch (Exception $e) {
		error_log("Upload error: " . $e->getMessage());
		$response->getBody()->write(json_encode([
			'status'  => 'error',
			'message' => $e->getMessage()
		]));
		return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
	}
})->add($authMiddleware);

$app->run();


function parseCsvFile($path)
{
	$headers = [];
	$rows = [];
	if (($handle = fopen($path, 'r')) !== false) {
		$rawHeaders = fgetcsv($handle);
		if ($rawHeaders !== false) {
			$headers = array_map(function($h) {
				return strtolower(trim((string)$h));
			}, $rawHeaders);

			while (($data = fgetcsv($handle)) !== false) {
				if (count($data) === 1 && ($data[0] === null || trim((string)$data[0]) === '')) {
					continue;
				}
				if (count($headers) === count($data)) {
					$cleanData = array_map('trim', $data);
					$rows[] = array_combine($headers, $cleanData);
				}
			}
		}
		fclose($handle);
	}
	return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Action A1: Migration from legacy patient data table to facility_monthly_usage.
 * Drops legacy data table only after aggregated totals match.
 */
function migrateDataToFacilityMonthlyUsage()
{
	try {
		if (!R::testConnection()) {
			return;
		}

		// Ensure target table exists
		R::exec("CREATE TABLE IF NOT EXISTS `facility_monthly_usage` (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Check if legacy table 'data' exists
		$tables = R::inspect();
		if (!in_array('data', $tables, true)) {
			return;
		}

		$rowCount = (int)R::getCell("SELECT COUNT(*) FROM `data`");
		if ($rowCount > 0) {
			$oldTotal = (float)R::getCell("SELECT SUM(estimate_need) FROM `data`");

			// Aggregate monthly totals per hospital
			$aggregated = R::getAll("
				SELECT 
					hospitalID, 
					DATE_FORMAT(date_used, '%Y-%m') AS period, 
					ROUND(SUM(estimate_need), 2) AS total_oxygen,
					MIN(created_at) AS created_at
				FROM `data`
				WHERE date_used IS NOT NULL AND date_used != '' AND date_used != '0000-00-00'
				GROUP BY hospitalID, DATE_FORMAT(date_used, '%Y-%m')
			");

			foreach ($aggregated as $row) {
				$hosId = (int)$row['hospitalID'];
				$period = $row['period'];
				$totalOxy = (float)$row['total_oxygen'];
				$createdAt = $row['created_at'] ?: date('Y-m-d H:i:s');

				R::exec("INSERT INTO `facility_monthly_usage` (`hospital_id`, `hospitalID`, `period`, `oxygen_used_m3`, `created_at`) 
						VALUES (?, ?, ?, ?, ?) 
						ON DUPLICATE KEY UPDATE `oxygen_used_m3` = VALUES(`oxygen_used_m3`)",
					[$hosId, $hosId, $period, $totalOxy, $createdAt]
				);
			}

			// Verify totals before dropping
			$newTotal = (float)R::getCell("SELECT SUM(oxygen_used_m3) FROM `facility_monthly_usage`");
			if (abs($oldTotal - $newTotal) < 0.05) {
				R::exec("DROP TABLE `data`");
				error_log("Migration successful: legacy `data` table dropped after matching totals ($oldTotal == $newTotal).");
			} else {
				error_log("Migration warning: totals mismatch ($oldTotal vs $newTotal). Legacy `data` table retained.");
			}
		} else {
			R::exec("DROP TABLE `data`");
		}
	} catch (Exception $e) {
		error_log("Data migration error: " . $e->getMessage());
	}
}
