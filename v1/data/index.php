<?php 
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\AuthService;
use App\Config\Database;

require '../../vendor/autoload.php';
require '../../include/dbsol/conn.php';

$authService = new AuthService();
$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? 'test';

$app = new \Slim\App;

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
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_EXPIRED',
				'message' => 'Token has expired'
			], JSON_UNESCAPED_SLASHES));
	} catch (\Firebase\JWT\SignatureInvalidException $e) {
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_INVALID_SIGNATURE',
				'message' => 'Invalid token signature'
			], JSON_UNESCAPED_SLASHES));
	} catch (Exception $e) {
		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'code'    => 'TOKEN_INVALID',
				'message' => 'Invalid token: ' . $e->getMessage()
			], JSON_UNESCAPED_SLASHES));
	}
};

$app->get('/', function (Request $request, Response $response, array $args) use ($authToken) {

	$authorization_header = $request->getHeader("Authorization");

	if (empty($authorization_header) || ($authorization_header[0] != $authToken)) {

		$return =  array('status' => 'false', 'Description' => 'Data Processing.', 'Message' => 'Header is missing', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return =  array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'method allowed,post', 'data' => null);

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

	$hospitalID = $request->getParam('hospitalID');
	if (empty($hospitalID) && !empty($user['ref_id'])) {
		$hospitalID = (int)$user['ref_id'];
	}

	$gender = $request->getParam('gender');
	$age = $request->getParam('age');
	$underlying_conditions = $request->getParam('underlying_conditions');
	$estimate_need = $request->getParam('estimate_need');
	$flow_rate = $request->getParam('flow_rate');
	$date_used = $request->getParam('date_used');
	$treatrment = $request->getParam('treatrment') ?? $request->getParam('treatment');

	try {
		$sql = "INSERT INTO `data` (`hospitalID`, `gender`, `age`, `underlying_conditions`, `estimate_need`, `flow_rate`, `treatrment`, `date_used`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

		$add = R::exec($sql, [$hospitalID, $gender, $age, $underlying_conditions, $estimate_need, $flow_rate, $treatrment, $date_used]);
		$id = R::getInsertID();

		$return = array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'data was successfully processed', 'data' => $id);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Data insert error: " . $e->getMessage());
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
		$refid = $user['ref_id'];

		$mainDb = Database::getMainDbName();
		$allUploadedData = R::findAll('data', 'hospitalID = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)', [$refid]);
		$month = R::getAll("SELECT SUM(estimate_need) as estimate_need, DATE_FORMAT(created_at, '%M') AS month_name, DATE_FORMAT(created_at, '%Y') AS month_year FROM `data` WHERE hospitalID = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at, '%Y-%m')", [$refid]);

		$lastSixMonthsUsage = R::getAll("SELECT DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y') AS month_year, DATE_FORMAT(FROM_UNIXTIME(o.tym), '%M') AS month_name, SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters FROM `{$mainDb}`.oxygen_order AS o LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id WHERE FROM_UNIXTIME(o.tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.order_by = ? GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') ORDER BY FROM_UNIXTIME(o.tym) DESC", [$refid]);
		$lastSixMonthPredict = R::getAll("SELECT p.predictions AS total_cubic_meters, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%Y') AS month_year, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%M') AS month_name FROM `predictions` p INNER JOIN (SELECT MAX(tym) AS max_tym FROM `predictions` WHERE (hospital_id = ? OR hospitalID = ?) AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(FROM_UNIXTIME(tym), '%Y-%m')) latest ON p.tym = latest.max_tym WHERE (p.hospital_id = ? OR p.hospitalID = ?) ORDER BY p.tym DESC", [$refid, $refid, $refid, $refid]);

		$data = ['chart' => ['predicted' => $lastSixMonthPredict, 'actual' => $lastSixMonthsUsage], 'data' => $allUploadedData, 'month' => $month];

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

        $refid = $user['ref_id'];
        $uploadedFiles = $request->getUploadedFiles();

        $records = [];

        
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

                $records = parseCsvFile($filePath);
                unlink($filePath); // cleanup temp file
            } else {
                throw new Exception('File upload failed.');
            }
        }
        
        else {
            $body = $request->getParsedBody();
            if (isset($body['templateData']) && is_array($body['templateData'])) {
                $records = $body['templateData'];
            } else {
                throw new Exception('Missing or invalid "templateData" in JSON body');
            }
        }

        if (empty($records)) {
            throw new Exception('No valid records found.');
        }

        $recordsAdded = 0;
        $errors = [];

        $requiredFields = ['gender', 'age', 'conditions', 'estimate_need', 'flow_rate', 'treatment', 'date_used'];

        foreach ($records as $i => $row) {
            $missing = [];
            foreach ($requiredFields as $field) {
                // Ensure 0 or "0" is accepted as valid, not missing (Item 30)
                $val = $row[$field] ?? null;
                if ($val === null || $val === '') {
                    // Check aliases / typo-tolerant fields
                    if ($field === 'treatment' && isset($row['treatrment']) && $row['treatrment'] !== '') continue;
                    if ($field === 'conditions' && isset($row['underlying_conditions']) && $row['underlying_conditions'] !== '') continue;
                    $missing[] = $field;
                }
            }

            if (!empty($missing)) {
                $errors[] = [
                    'index'          => $i,
                    'missing_fields' => array_values($missing)
                ];
                continue;
            }

            $treatmentVal = $row['treatment'] ?? $row['treatrment'] ?? '';
            $conditionVal = $row['conditions'] ?? $row['underlying_conditions'] ?? '';

            $sql = "INSERT INTO `data`
                    (`hospitalID`, `gender`, `age`, `underlying_conditions`, `estimate_need`, `flow_rate`, `treatrment`, `date_used`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            R::exec($sql, [
                $refid,
                $row['gender'],
                $row['age'],
                $conditionVal,
                $row['estimate_need'],
                $row['flow_rate'],
                $treatmentVal,
                $row['date_used'],
            ]);

            $recordsAdded++;
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'description' => 'Records added successfully',
            'data' => [
                'records_added' => $recordsAdded,
                'records_failed' => count($errors),
                'errors' => $errors
            ]
        ], JSON_PRETTY_PRINT));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

$app->run();


function parseCsvFile($path)
{
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        $headers = fgetcsv($handle);
        if ($headers !== false) {
            $headers = array_map('trim', $headers);
            while (($data = fgetcsv($handle)) !== false) {
                // Guard against row length mismatch (Item 30)
                if (count($headers) === count($data)) {
                    $rows[] = array_combine($headers, array_map('trim', $data));
                }
            }
        }
        fclose($handle);
    }
    return $rows;
}

