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

$app->get('/', function (Request $request, Response $response, array $args) use ($authToken) {

	$authorization_header = $request->getHeader("Authorization");

	if (empty($authorization_header) || ($authorization_header[0] != $authToken)) {

		$return =  array('status' => 'false', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'Header is missing', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return =  array('status' => 'success', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'method allowed,post', 'data' => null);

	return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
});

$app->post('/login', function (Request $request, Response $response) use ($authService) {

	$user_id = $request->getParam('email');
	$pwd = $request->getParam('password');

	// Ensure that email and password are not empty
	if (empty($user_id) || empty($pwd)) {
		$return = array('status' => 'false', 'Message' => 'email and password are required', 'data' => null);
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	try {
		$mainDb = Database::getMainDbName();
		$login = R::getRow("SELECT * FROM `{$mainDb}`.`secure_login` WHERE `email` = ?", [$user_id]);

		if ($login === null || !$authService->verifyPassword($pwd, $login['password'])) {
			$return = array('status' => 'false', 'Message' => 'Invalid email or password', 'data' => null);
			return $response->withStatus(401)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode($return));
		}

		// Rehash legacy password to modern bcrypt if needed
		if ($authService->needsRehash($login['password'])) {
			$newHash = $authService->hashPassword($pwd);
			R::exec("UPDATE `{$mainDb}`.`secure_login` SET `password` = ? WHERE `memberid` = ?", [$newHash, $login['memberid']]);
		}

		$jwt = $authService->generateToken([
			'email' => $login['email'],
			'ref_id' => $login['memberid']
		]);

		$safeUserData = [
			'email'  => $login['email'],
			'ref_id' => $login['memberid'],
			'type'   => $login['type'] ?? 'hospital'
		];

		$return = array('status' => 'success', 'Message' => 'user found.', 'data' => $safeUserData, 'token' => $jwt);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Login error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
});

$hospitalService = new \App\Services\HospitalService();

$requireSupervisorOrToken = function ($request, $response, $next) use ($authService, $authToken) {
	$authHeader = $request->getHeaderLine("Authorization");

	if ($authHeader && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
		try {
			$decoded = $authService->decodeToken($matches[1]);
			$request = $request->withAttribute('user', $decoded);
			if (($decoded['type'] ?? '') === 'supervisor') {
				return $next($request, $response);
			}
		} catch (Exception $e) {
			// Fall through
		}
	}

	$tokenToCheck = $authHeader;
	if (preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
		$tokenToCheck = $matches[1];
	}
	if (!empty($tokenToCheck) && hash_equals((string)$authToken, (string)$tokenToCheck)) {
		return $next($request, $response);
	}

	return $response->withStatus(401)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode([
			'status'  => 'error',
			'message' => 'Unauthorized: Supervisor access or valid authorization token required'
		]));
};

$app->post('/add/user', function (Request $request, Response $response) use ($authService, $hospitalService) {

	// Personal information
	$firstname   = trim((string)$request->getParam('firstname'));
	$lastname    = trim((string)$request->getParam('lastname'));
	$phone       = trim((string)$request->getParam('phone'));
	$email       = trim((string)$request->getParam('email'));
	$designation = trim((string)$request->getParam('designation'));
	$raw_pwd     = (string)$request->getParam('password');

	// Input validation (Item 12)
	if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'A valid email address is required']));
	}

	if (strlen($raw_pwd) < 8) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long']));
	}

	// Reject duplicate email
	$existing = R::findOne('user', 'email = ?', [$email]);
	if ($existing) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Email is already registered']));
	}

	$pwd = $authService->hashPassword($raw_pwd);
	$contactPerson = trim($firstname . ' ' . $lastname);

	// Hospital information
	$hospitalData = [
		'name'          => $request->getParam('hos_name') ?? $request->getParam('name'),
		'address_1'     => $request->getParam('address'),
		'address_2'     => $request->getParam('address_1'),
		'type'          => $request->getParam('type'),
		'city'          => $request->getParam('city'),
		'state'         => $request->getParam('states') ?? $request->getParam('state'),
		'bed'           => $request->getParam('bed'),
		'depart'        => $request->getParam('depart'),
		'oSource'       => $request->getParam('oSource'),
		'power'         => $request->getParam('power'),
		'technical'     => $request->getParam('technical'),
		'contactPerson' => $contactPerson,
		'designation'   => $designation,
		'phone'         => $phone,
		'email'         => $email
	];

	try {
		// Unified HospitalService::createHospital (Item 23)
		$org = $hospitalService->createHospital($hospitalData);

		saveUser($email, $pwd, $org, 'hospital');

		$return = array('status' => 'success', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'hospital was created.', 'data' => $org);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("User registration error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
})->add($requireSupervisorOrToken);

$app->post('/supervisor/add/user', function (Request $request, Response $response) use ($authService, $hospitalService) {

	// Personal information
	$firstname   = trim((string)$request->getParam('firstname'));
	$lastname    = trim((string)$request->getParam('lastname'));
	$phone       = trim((string)$request->getParam('phone'));
	$email       = trim((string)$request->getParam('email'));
	$designation = trim((string)$request->getParam('designation'));
	$raw_pwd     = (string)$request->getParam('password');

	// Input validation (Item 12)
	if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'A valid email address is required']));
	}

	if (strlen($raw_pwd) < 8) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long']));
	}

	// Reject duplicate email
	$existing = R::findOne('user', 'email = ?', [$email]);
	if ($existing) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Email is already registered']));
	}

	$pwd = $authService->hashPassword($raw_pwd);
	$contactPerson = trim($firstname . ' ' . $lastname);

	// Hospital information
	$hospitalData = [
		'name'          => $request->getParam('hos_name') ?? $request->getParam('name'),
		'address_1'     => $request->getParam('address'),
		'address_2'     => $request->getParam('address_1'),
		'type'          => $request->getParam('type'),
		'city'          => $request->getParam('city'),
		'state'         => $request->getParam('states') ?? $request->getParam('state'),
		'bed'           => $request->getParam('bed'),
		'depart'        => $request->getParam('depart'),
		'oSource'       => $request->getParam('oSource'),
		'power'         => $request->getParam('power'),
		'technical'     => $request->getParam('technical'),
		'contactPerson' => $contactPerson,
		'designation'   => $designation,
		'phone'         => $phone,
		'email'         => $email
	];

	try {
		// Unified HospitalService::createHospital (Item 23)
		$org = $hospitalService->createHospital($hospitalData);

		saveUser($email, $pwd, $org, 'supervisor');

		$return = array('status' => 'success', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'hospital was created.', 'data' => $org);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Supervisor registration error: " . $e->getMessage());
		$error = array('status' => 'error', 'Message' => 'An internal server error occurred');
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($error));
	} finally {
		R::close();
	}
})->add($requireSupervisorOrToken);


$app->run();

function saveUser($email, $pwd, $org, $privileges)
{
	$user = R::dispense('user');

	$user->email = $email;
	$user->pwd = $pwd;
	$user->privileges = $privileges;
	$user->org_id = $org;

	//retrieve id
	$id = R::store($user);

	//return stored id  
	return $id;
}
