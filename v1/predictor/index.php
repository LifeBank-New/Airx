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

// Action B2: Hard stop if AUTH_TOKEN is missing or set to 'test'
$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? '';
if (strlen($authToken) < 24 || $authToken === 'test') {
	throw new RuntimeException('AUTH_TOKEN must be set to a long random value');
}

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

	// Action B1: Take the hospital from the login token, not the request
	$hospitalid = !empty($user['ref_id'])
		? (int)$user['ref_id']                          // logged-in hospital: always its own ID
		: (int)$request->getParam('hospitalid');        // shared-token callers only

	// Guard against hospital 0 (Item 25)
	if ($hospitalid <= 0) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'Message' => 'Valid hospital ID is required']));
	}

	$time = time();

	try {
		$params = $request->getParams();
		// Action C5: Handle typos in parameter names
		if (!isset($params['peaditric']) && isset($params['pediatric'])) {
			$params['peaditric'] = $params['pediatric'];
		}
		if (!isset($params['materinity']) && isset($params['maternity'])) {
			$params['materinity'] = $params['maternity'];
		}

		$needs = $predictorService->calculateNeeds($params);
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
		$params = $request->getParams();
		// Action C5: Handle typos in parameter names
		if (!isset($params['peaditric']) && isset($params['pediatric'])) {
			$params['peaditric'] = $params['pediatric'];
		}
		if (!isset($params['materinity']) && isset($params['maternity'])) {
			$params['materinity'] = $params['maternity'];
		}

		$needs = $predictorService->calculateSupervisorNeeds($params);

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
		// Action B6: Mask internal errors
		error_log("Predictor hospitals error: " . $e->getMessage());
		$errorPayload = [
			'status'  => 'error',
			'message' => 'An internal server error occurred'
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
		// Action B1: Take hospital ID from JWT ref_id when present
		$refid = !empty($user['ref_id'])
			? (int)$user['ref_id']
			: (int)($request->getParam('hospital_id') ?? $request->getParam('ref_id') ?? $request->getParam('hospitalid'));

		if (empty($refid)) {
			return $response->withStatus(400)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode(['status' => 'error', 'message' => 'Hospital ID is required']));
		}

		$mainDb = Database::getMainDbName();

		// Fetch up to 12 most recent monthly usage records
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

		// Action A1 (Code A1): Fallback to facility_monthly_usage if no orders exist
		if (empty($history)) {
			try {
				$history = R::getAll(
					"SELECT `period` AS month, `oxygen_used_m3` AS total_cubic_meters
					 FROM `facility_monthly_usage` WHERE hospital_id = ? ORDER BY `period` DESC LIMIT 12",
					[$refid]
				);
			} catch (Exception $e) {
				$history = [];
			}
			if (empty($history)) {
				try {
					$history = R::getAll(
						"SELECT `period` AS month, `oxygen_used_m3` AS total_cubic_meters
						 FROM `facility_monthly_usage` WHERE hospitalID = ? ORDER BY `period` DESC LIMIT 12",
						[$refid]
					);
				} catch (Exception $e) {
					$history = [];
				}
			}
		}

		// Re-sort chronologically ascending for time-series forecasting
		if (!empty($history)) {
			usort($history, function($a, $b) {
				return strcmp($a['month'], $b['month']);
			});
		}

		// Enrich history records with weather statistics (temperature and humidity)
		$hospitalRow = R::getRow("SELECT city FROM `{$mainDb}`.hospital WHERE id = ? LIMIT 1", [$refid]);
		$city = !empty($hospitalRow['city']) ? $hospitalRow['city'] : 'Lagos';

		foreach ($history as &$row) {
			$weather = getWeatherStats($row['month'], $city);
			$row['avg_temp'] = $weather['temperature'];
			$row['avg_humidity'] = $weather['humidity'];
		}
		unset($row);

		$validatedHistory = validateHistoricalData($history);

		if (empty($validatedHistory)) {
			return $response->withStatus(200)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode([
					'status' => 'success',
					'message' => 'No historical data available for accurate forecasting. Advice: Log monthly facility oxygen usage or place oxygen orders to establish baseline.',
					'data' => [
						'prediction' => 0.0,
						'method' => 'no_data',
						'accuracy' => 0.0,
						'historical_months_count' => 0,
						'historical_data' => []
					]
				], JSON_UNESCAPED_SLASHES));
		}

		$locationFactor = 1.0;
		$prediction = $predictorService->predictMonthlyNeed($validatedHistory, $locationFactor);

		$result = [
			'status' => 'success',
			'data' => [
				'prediction' => $prediction['prediction'],
				'method' => $prediction['method'],
				'accuracy' => $prediction['accuracy'],
				'historical_months_count' => count($validatedHistory),
				'historical_data' => $validatedHistory
			]
		];

		// Persist valid predictions to telemetry store
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
		// Action A2: Remove hospitalID reference
		$book = R::find('predictions', 'hospital_id = ? ORDER BY tym DESC', [$id]);

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

	// Action A2: hospital_id only
	$prediction->hospital_id = $hospital;
	$prediction->predictions = $result['prediction'] ?? 0;
	$prediction->method = $result['method'] ?? null;
	$prediction->accuracy = $result['accuracy'] ?? 0;
	$prediction->tym = $time;

	// retrieve id
	$id = R::store($prediction);

	// return store id  
	return $id;
}
