<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\AuthService;
use App\Services\HospitalService;
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

$app = new \Slim\App;

$app->get('/', function (Request $request, Response $response, array $args) use ($authToken) {

	$authorization_header = $request->getHeader("Authorization");

	if (empty($authorization_header) || !hash_equals((string)$authToken, (string)$authorization_header[0])) {

		$return = array('status' => 'false', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'Header is missing or invalid', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return = array('status' => 'success', 'Description' => 'This is a set of credentials used to authenticate a user', 'Message' => 'method allowed,post', 'data' => null);

	return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
});

$app->post('/login', function (Request $request, Response $response) use ($authService) {

	$user_id = trim((string)$request->getParam('email'));
	$pwd = (string)$request->getParam('password');

	// Ensure that email and password are not empty
	if (empty($user_id) || empty($pwd)) {
		$return = array('status' => 'false', 'Message' => 'email and password are required', 'data' => null);
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	try {
		$mainDb = Database::getMainDbName();

		// Action B5: Rate limiting - return 429 after 5 failed attempts per IP or email within 15 minutes
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		if (strpos($ip, ',') !== false) {
			$ip = trim(explode(',', $ip)[0]);
		}

		$windowStart = time() - 900; // 15 minutes
		try {
			$failedAttempts = (int)R::getCell(
				"SELECT COUNT(*) FROM `login_attempts` WHERE (ip = ? OR email = ?) AND tym >= ?",
				[$ip, $user_id, $windowStart]
			);

			if ($failedAttempts >= 5) {
				return $response->withStatus(429)
					->withHeader('Content-Type', 'application/json')
					->write(json_encode([
						'status'  => 'error',
						'code'    => 'RATE_LIMIT_EXCEEDED',
						'message' => 'Too many failed login attempts. Please try again after 15 minutes.'
					]));
			}
		} catch (Exception $e) {
			// Auto-create login_attempts table if not yet present
			try {
				R::exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
					`id` INT AUTO_INCREMENT PRIMARY KEY,
					`ip` VARCHAR(64) NOT NULL,
					`email` VARCHAR(191) NOT NULL,
					`tym` INT NOT NULL,
					KEY `idx_ip_tym` (`ip`, `tym`),
					KEY `idx_email_tym` (`email`, `tym`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			} catch (Exception $ex) {}
		}

		// Action B4: Look in secure_login or read from user table
		$login = R::getRow("SELECT * FROM `{$mainDb}`.`secure_login` WHERE `email` = ?", [$user_id]);
		if (!$login) {
			$u = R::getRow("SELECT * FROM `user` WHERE `email` = ?", [$user_id]);
			if ($u) {
				$login = [
					'email'    => $u['email'],
					'password' => $u['pwd'],
					'memberid' => $u['org_id'],
					'type'     => $u['privileges'] ?? 'hospital'
				];
			}
		}

		if ($login === null || !$authService->verifyPassword($pwd, $login['password'])) {
			// Record failed attempt for rate limiting
			try {
				R::exec("INSERT INTO `login_attempts` (`ip`, `email`, `tym`) VALUES (?, ?, ?)", [$ip, $user_id, time()]);
			} catch (Exception $e) {}

			$return = array('status' => 'false', 'Message' => 'Invalid email or password', 'data' => null);
			return $response->withStatus(401)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode($return));
		}

		// Clear failed attempts upon successful login
		try {
			R::exec("DELETE FROM `login_attempts` WHERE ip = ? OR email = ?", [$ip, $user_id]);
		} catch (Exception $e) {}

		// Action B3: Disable rehash block that rewrites old MD5/SHA-512 hashes to bcrypt
		// until every LifeBank product sharing secure_login uses password_verify().
		/*
		if ($authService->needsRehash($login['password'])) {
			$newHash = $authService->hashPassword($pwd);
			R::exec("UPDATE `{$mainDb}`.`secure_login` SET `password` = ? WHERE `memberid` = ?", [$newHash, $login['memberid']]);
		}
		*/

		// Action B2: Add 'type' => $login['type'] ?? 'hospital' to the token
		$userType = $login['type'] ?? 'hospital';
		$jwt = $authService->generateToken([
			'email'  => $login['email'],
			'ref_id' => $login['memberid'],
			'type'   => $userType
		]);

		$safeUserData = [
			'email'  => $login['email'],
			'ref_id' => $login['memberid'],
			'type'   => $userType
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

$hospitalService = new HospitalService();

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
			error_log("Supervisor auth token error: " . $e->getMessage());
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

	// Reject duplicate email in user and secure_login
	$existing = R::findOne('user', 'email = ?', [$email]);
	if ($existing) {
		return $response->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'Email is already registered']));
	}

	$pwd = $authService->hashPassword($raw_pwd);
	$contactPerson = trim($firstname . ' ' . $lastname);

	// Action B4: Align address_1/address_2 with addressLine1/addressLine2
	$address1 = $request->getParam('addressLine1') ?? $request->getParam('address_1') ?? $request->getParam('address');
	$address2 = $request->getParam('addressLine2') ?? $request->getParam('address_2');

	$hospitalData = [
		'name'          => $request->getParam('hos_name') ?? $request->getParam('name'),
		'address_1'     => $address1,
		'address_2'     => $address2,
		'addressLine1'  => $address1,
		'addressLine2'  => $address2,
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

		// Action B4: Write new logins to secure_login and user
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

	// Action B4: Align address_1/address_2 with addressLine1/addressLine2
	$address1 = $request->getParam('addressLine1') ?? $request->getParam('address_1') ?? $request->getParam('address');
	$address2 = $request->getParam('addressLine2') ?? $request->getParam('address_2');

	$hospitalData = [
		'name'          => $request->getParam('hos_name') ?? $request->getParam('name'),
		'address_1'     => $address1,
		'address_2'     => $address2,
		'addressLine1'  => $address1,
		'addressLine2'  => $address2,
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

		// Action B4: Write new logins to secure_login and user
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

/**
 * Action B4: Save user in both user and secure_login tables so new accounts can log in.
 */
function saveUser($email, $pwd, $org, $privileges)
{
	$mainDb = Database::getMainDbName();

	$user = R::dispense('user');
	$user->email = $email;
	$user->pwd = $pwd;
	$user->privileges = $privileges;
	$user->org_id = $org;
	$id = R::store($user);

	// Also write to secure_login
	try {
		$existing = R::getRow("SELECT id FROM `{$mainDb}`.`secure_login` WHERE `email` = ?", [$email]);
		if (!$existing) {
			R::exec(
				"INSERT INTO `{$mainDb}`.`secure_login` (`email`, `password`, `memberid`, `type`, `created_at`) VALUES (?, ?, ?, ?, NOW())",
				[$email, $pwd, (string)$org, $privileges]
			);
		}
	} catch (Exception $e) {
		error_log("Failed to insert into secure_login: " . $e->getMessage());
	}

	return $id;
}
