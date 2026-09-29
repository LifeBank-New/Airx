<?php 
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\AuthService;
use App\Services\PredictorService;
use App\Services\HospitalService;
use App\Config\Database;

require '../../vendor/autoload.php';
require '../../include/dbsol/conn.php';
include_once '../../include/functions/helper.php';

$authService = new AuthService();
$predictorService = new PredictorService();
$hospitalService = new HospitalService();
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

	if (empty($authorization_header) || !hash_equals((string)$authToken, (string)$authorization_header[0])) {

		$return =  array('status' => 'false', 'Description' => 'Data Processing.', 'Message' => 'Header is missing or invalid', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return =  array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'method allowed,post', 'data' => null);

	return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
});

$app->post('/run', function (Request $request, Response $response) use ($authToken, $authService, $predictorService) {

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

	$hospitalid = (int)$request->getParam('hospitalid');
	if ($hospitalid <= 0 && !empty($user['ref_id'])) {
		$hospitalid = (int)$user['ref_id'];
	}

	// Guard against hospital 0 (Item 25)
	if ($hospitalid <= 0) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'Message' => 'Valid hospital ID is required']));
	}

	$time = time();

	try {
		$needs = $predictorService->calculateNeeds($request->getParams());
		$predictorService->savePrediction($hospitalid, $needs, $time);

		$return = array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'data was successfully processed', 'data' => $needs);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Predictor run error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
});

$app->post('/run/supervisor', function (Request $request, Response $response) use ($authToken, $authService, $predictorService) {

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

	$isSupervisor = $user && (($user['type'] ?? '') === 'supervisor');
	$hasToken = !empty($tokenToCheck) && hash_equals((string)$authToken, (string)$tokenToCheck);

	if (!$isSupervisor && !$hasToken) {
		$return = array('status' => 'false', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'Unauthorized: Supervisor access required', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	try {
		$needs = $predictorService->calculateSupervisorNeeds($request->getParams());

		$return = array('status' => 'success', 'Description' => 'Data Processing.', 'Message' => 'data was successfully processed', 'data' => $needs);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Predictor run/supervisor error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
});

$app->get('/hospitals', function (Request $request, Response $response) use ($hospitalService) {
	try {
		$user = $request->getAttribute('user');
		$ref_id = (int)($user['ref_id'] ?? 0);

		$data = $hospitalService->getDashboardData($ref_id);

		$payload = [
			'status'      => 'success',
			'description' => 'Hospital dashboard data endpoint',
			'data'        => $data
		];

		$response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT));

		return $response
			->withHeader('Content-Type', 'application/json')
			->withStatus(200);
	} catch (Exception $e) {
		$errorPayload = [
			'status'  => 'error',
			'message' => 'System failure',
			'details' => $e->getMessage()
		];

		$response->getBody()->write(json_encode($errorPayload, JSON_PRETTY_PRINT));

		return $response
			->withHeader('Content-Type', 'application/json')
			->withStatus(500);
	} finally {
		R::close();
	}
})->add($authMiddleware);

$app->get('/hospital/predict', function (Request $request, Response $response) use ($predictorService) {
	try {
		$user = $request->getAttribute('user');
		$refid = $user['ref_id'] ?? $request->getParam('hospital_id') ?? $request->getParam('ref_id') ?? null;

		if (!$refid) {
			return $response->withStatus(400)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode([
					'status' => 'error',
					'message' => 'Missing hospital reference ID'
				]));
		}

		// Validate and sanitize input
		$refid = filter_var($refid, FILTER_VALIDATE_INT);
		if ($refid === false || $refid <= 0) {
			return $response->withStatus(400)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode([
					'status' => 'error',
					'message' => 'Invalid hospital reference ID'
				]));
		}

		$mainDb = Database::getMainDbName();
		$state = R::getCell(
			"SELECT user_info.state FROM `{$mainDb}`.`secure_login` LEFT JOIN `{$mainDb}`.user_info ON user_info.ref_id = secure_login.memberid WHERE memberid = ?",
			[$refid]
		);

		// Fetch latest 12 months of historical consumption from completed oxygen orders
		$history = R::getAll("
            SELECT 
                DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') AS month,
                SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters
            FROM `{$mainDb}`.oxygen_order AS o
            LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id
            WHERE o.order_by = ?
            GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m')
            ORDER BY month DESC
            LIMIT 12
        ", [$refid]);

		// Fallback to internal consumption log if no orders exist
		if (empty($history)) {
			$history = R::getAll(
				"SELECT DATE_FORMAT(date_used, '%Y-%m') AS month, SUM(estimate_need) AS total_cubic_meters FROM `data` WHERE hospitalID = ? GROUP BY DATE_FORMAT(date_used, '%Y-%m') ORDER BY month DESC LIMIT 12",
				[$refid]
			);
		}

		// Re-sort chronologically ascending for time-series forecasting
		if (!empty($history)) {
			usort($history, function($a, $b) {
				return strcmp($a['month'], $b['month']);
			});
		}

		// Enrich history records with weather statistics (temperature and humidity)
		foreach ($history as &$h) {
			$weather = getWeatherStats($h['month'] ?? null, $state);
			$h['avg_temp'] = $weather['temperature'];
			$h['avg_humidity'] = $weather['humidity'];
		}
		unset($h);

		// Sanitize and validate history data format
		$validatedHistory = validateHistoryData($history);

		$locationFactor = 1.0; // Scalable location factor

		// Run actual prediction model
		$prediction = $predictorService->predictMonthlyNeed($validatedHistory, $locationFactor);

		$result = [
			'status' => 'success',
			'description' => empty($validatedHistory)
				? 'No historical consumption data available for prediction'
				: 'Predicted oxygen requirement for next month',
			'hospital_id' => $refid,
			'data' => [
				'prediction_cubic_meters' => $prediction['prediction'],
				'method' => $prediction['method'],
				'accuracy' => $prediction['accuracy'],
				'history_count' => count($validatedHistory)
			]
		];

		if (!empty($validatedHistory) && $prediction['method'] !== 'no_data') {
			saveRun($refid, $prediction, time());
		}

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($result, JSON_UNESCAPED_SLASHES));
	} catch (Exception $e) {
		error_log("Prediction failed: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status' => 'error',
				'message' => 'Prediction service temporarily unavailable'
			]));
	} finally {
		R::close();
	}
})->add($authMiddleware);

$app->get('/view/{id}', function (Request $request, Response $response, array $args) use ($authToken, $authService) {

	$id = (int)$request->getAttribute('id');
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

	$isSupervisor = $user && (($user['type'] ?? '') === 'supervisor');
	$isOwner = $user && ((int)($user['ref_id'] ?? 0) === $id);
	$hasToken = !empty($tokenToCheck) && hash_equals((string)$authToken, (string)$tokenToCheck);

	if (!$isSupervisor && !$isOwner && !$hasToken) {
		$return = array('status' => 'false', 'Description' => 'Data Processing.', 'Message' => 'Unauthorized: Valid credentials required', 'data' => null);

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	try {
		$book = R::find('predictions', 'hospital_id = ? OR hospitalID = ? ORDER BY tym DESC', [$id, $id]);

		$return = array('status' => 'success', 'Description' => 'Predictions history.', 'Message' => 'data was collected', 'data' => array_values($book));

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Prediction view error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status' => 'error',
				'message' => 'Unable to retrieve predictions history'
			]));
	} finally {
		R::close();
	}
});

$app->run();


function saveRun($hospital, $result, $time)
{
	$prediction = R::dispense('predictions');

	$prediction->hospital_id = $hospital;
	$prediction->hospitalID = $hospital;
	$prediction->predictions = $result['prediction'] ?? 0;
	$prediction->method = $result['method'] ?? null;
	$prediction->accuracy = $result['accuracy'] ?? 0;
	$prediction->tym = $time;

	// retrieve id
	$id = R::store($prediction);

	// return store id  
	return $id;
}
