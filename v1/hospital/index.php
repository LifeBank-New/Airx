<?php 
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\AuthService;
use App\Services\HospitalService;
use App\Config\Database;

require '../../vendor/autoload.php';
require '../../include/dbsol/conn.php';

$authService = new AuthService();
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

	if (empty($authorization_header) || ($authorization_header[0] != $authToken)) {

		$return =  array('status' => 'false', 'Description' => 'hospital informations endpoints', 'Message' => 'Header is missing', 'data' => 'method allowed post');

		return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	}

	$return =  array('status' => 'success', 'Description' => 'hospital informations endpoints', 'Message' => 'method allowed,post', 'data' => null);

	return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
});

$app->get('/dashboard', function (Request $request, Response $response, array $args) {

	$authHeader = $request->getHeaderLine('Authorization'); // returns a string instead of an array

	try {

		$user = $request->getAttribute('user');
		 $refid = $user['ref_id'];
		//$refid = "416752380";

		$mainDb = Database::getMainDbName();

		$lastSixMonthsUsage = R::getAll("SELECT DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y') AS month_year, DATE_FORMAT(FROM_UNIXTIME(o.tym), '%M') AS month_name, SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters FROM `{$mainDb}`.oxygen_order AS o LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id WHERE FROM_UNIXTIME(o.tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.order_by = ? GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') ORDER BY FROM_UNIXTIME(o.tym) DESC", [$refid]);
		$lastSixMonthPredict = R::getAll("SELECT p.predictions AS total_cubic_meters, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%Y') AS month_year, DATE_FORMAT(FROM_UNIXTIME(p.tym), '%M') AS month_name FROM `predictions` p INNER JOIN (SELECT MAX(tym) AS max_tym FROM `predictions` WHERE (hospital_id = ? OR hospitalID = ?) AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(FROM_UNIXTIME(tym), '%Y-%m')) latest ON p.tym = latest.max_tym WHERE (p.hospital_id = ? OR p.hospitalID = ?) ORDER BY p.tym DESC", [$refid, $refid, $refid, $refid]);
		$last_order = R::getRow("SELECT *, (SELECT size FROM `{$mainDb}`.oxygen WHERE oxygen.id = `{$mainDb}`.oxygen_order.product) AS size FROM `{$mainDb}`.oxygen_order WHERE order_by = ? ORDER BY tym DESC LIMIT 1", [$refid]);

		if (empty($last_order) || empty($last_order["schedule_date"]) || $last_order["schedule_date"] === "0000-00-00") {
			$delivery_day = "No upcoming delivery";
		} else {
			$delivery_day = $last_order["schedule_date"];
		}

		// Select the latest prediction explicitly
		$latestPredictRow = !empty($lastSixMonthPredict) ? $lastSixMonthPredict[0] : null;
		$forecastStock = $latestPredictRow ? (float)($latestPredictRow["total_cubic_meters"] ?? 0) : 0;

		$top = ["stock" => $forecastStock, "forecast_need" => $forecastStock, "days" => 30, "delivery_day" => $delivery_day];

		$return = array('status' => 'success', 'Description' => 'hospital informations endpoints', 'data' => ['lastSixMonthsUsage' => $lastSixMonthsUsage, 'lastSixMonthPredict' => $lastSixMonthPredict, "last_order" => $last_order ? [$last_order] : [], "top" => $top]);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Dashboard fetch error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'  => 'error',
				'message' => 'Unable to load hospital dashboard'
			]));
	} finally {
		R::close();
	}
})->add($authMiddleware);

$app->get('/orders', function (Request $request, Response $response, array $args) {

	try {

		$user = $request->getAttribute('user');
		$refid = $user['ref_id'];
		// $refid = "416752380";

		$orders = R::getAll("SELECT *,(SELECT size from lifebank_plus.oxygen WHERE oxygen.id = lifebank_plus.oxygen_order.product)as size FROM lifebank_plus.oxygen_order WHERE order_by = ? AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) ORDER BY tym DESC", [$refid]);
		$countCancelled = R::getCell("SELECT COUNT(*) FROM lifebank_plus.`oxy_cancel` WHERE order_by = ? AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)", [$refid]);


		//count orders with status as completed
		$completed = 0;
		$pending = 0;
		$proccessing = 0;

		foreach ($orders as $order) {

			$status = strtolower($order['order_state']);

			if ($status == "completed") {
				$completed++;
			}

			if ($status  == "awaiting pick up") {
				$pending++;
			}

			if ($status  != "awaiting pick up" && $status  != "completed") {
				$proccessing++;
			}
		}

		$data = ["pending" => $pending, "processing" => $proccessing, "cancelled" => $countCancelled, "completed" => $completed, "orders" => $orders];

		$return =  array('status' => 'success', 'Description' => 'hospital informations endpoints',  'data' => $data);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Orders retrieval error: " . $e->getMessage());
		$response->getBody()->write(json_encode([
			'status'  => 'error',
			'message' => 'An internal server error occurred'
		]));
		return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
	}
})->add($authMiddleware);

$app->get('/product/size', function (Request $request, Response $response, array $args) {

	try {

		$user = $request->getAttribute('user');
		$refid = $user['ref_id'];

		$mainDb = Database::getMainDbName();
		$product_list = R::getAll("SELECT id,size FROM `{$mainDb}`.`oxygen`");

		$return =  array('status' => 'success', 'Description' => 'hospital informations endpoints',  'data' => $product_list);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Product size lookup error: " . $e->getMessage());
		$response->getBody()->write(json_encode([
			'status'  => 'error',
			'message' => 'An internal server error occurred'
		]));
		return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
	}
})->add($authMiddleware);

$app->post('/pricing', function (Request $request, Response $response, array $args) {

	try {

		$parsedBody = $request->getParsedBody();

		$user = $request->getAttribute('user');
		$refid = $user['ref_id'];

		$productype = $parsedBody['productid'] ?? null;

		$map = ["Large Cylinder" => '8 cubic meter', "Medium Cylinder" => '6 cubic meter', "Small Cylinder" => '2 cubic meter',];
		$productype = $map[$productype] ?? $productype;

		$product_price =  pricing($refid, $productype);

		$return =  array('status' => 'success', 'Description' => 'hospital informations endpoints',  'data' =>  $product_price);

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Pricing calculation error: " . $e->getMessage());
		$response->getBody()->write(json_encode([
			'status'  => 'error',
			'message' => 'An internal server error occurred'
		]));
		return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
	}
})->add($authMiddleware);


$app->post('/placeorder', function (Request $request, Response $response) {
    try {
        $parsedBody = $request->getParsedBody() ?? [];

        // ✅ Get user info from JWT
        $user = $request->getAttribute('user');
        if (empty($user) || empty($user['ref_id'])) {
            throw new Exception("User authentication failed or missing reference ID");
        }
        $refid = $user['ref_id'];

        // ✅ Extract request data
        $productType   = trim($parsedBody['productid'] ?? '');
        $qty           = (int)($parsedBody['qty'] ?? 0);
        $payment       = trim($parsedBody['payment'] ?? '');
        $usage         = trim($parsedBody['usage'] ?? '');
        $urgence       = trim($parsedBody['urgency'] ?? $parsedBody['requestType'] ?? 'Normal');
        $orderType     = trim($parsedBody['orderType'] ?? '');
        $scheduleDate  = trim($parsedBody['schedule_date'] ?? '0000-00-00');
        $scheduleTime  = trim($parsedBody['schedule_time'] ?? '');
        $discount      = 0.0; // Server-enforced discount calculation (disallow arbitrary client values)
        $requester     = trim($parsedBody['requester'] ?? '');

        // Static/default fields
        $channel       = "Nerve";
        $channelType   = "AirX";
        $status        = "Awaiting Pick Up";
        $createdAt     = time();

        // REQUIRED FIELDS CHECK
        $requiredFields = [
            'productid'      => $productType,
            'qty'            => $qty,
            'payment'        => $payment,
            'orderType'      => $orderType,
            'requester'      => $requester
        ];

        $missing = [];
        foreach ($requiredFields as $key => $value) {
            if ($value === '' || $value === null || $value === 0) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $response->getBody()->write(json_encode([
                'status'  => 'error',
                'message' => 'Missing required fields: ' . implode(', ', $missing)
            ], JSON_PRETTY_PRINT));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $mainDb = Database::getMainDbName();

        // Validate quantity between 1 and sane maximum (500)
        if ($qty < 1 || $qty > 500) {
            throw new Exception("Quantity must be between 1 and 500 cylinders");
        }

        // Normalize and resolve product by numeric ID or size string
        if (is_numeric($productType)) {
            $productRecord = R::getRow("SELECT id, size FROM `{$mainDb}`.`oxygen` WHERE id = ?", [(int)$productType]);
        } else {
            $map = [
                "Large Cylinder"  => '8 Cubic Meter',
                "Medium Cylinder" => '6 Cubic Meter',
                "Small Cylinder"  => '2 Cubic Meter'
            ];
            $targetSize = $map[$productType] ?? $productType;
            $productRecord = R::getRow("SELECT id, size FROM `{$mainDb}`.`oxygen` WHERE size LIKE ? LIMIT 1", ["%$targetSize%"]);
        }

        if (!$productRecord) {
            throw new Exception("Invalid product ID or product not found: $productType");
        }

        $productId = (int)$productRecord['id'];
        $productSize = $productRecord['size'];

        // Guard against duplicate rapid order submission (within 30 seconds)
        $recentOrder = R::getRow(
            "SELECT id FROM `{$mainDb}`.oxygen_order WHERE order_by = ? AND product = ? AND qty = ? AND tym >= ? LIMIT 1",
            [$refid, $productId, (int)$qty, $createdAt - 30]
        );
        if ($recentOrder) {
            throw new Exception("Duplicate order submission detected. Please wait before placing another identical order.");
        }

        $productPrice = pricing($refid, $productSize);
        if ($productPrice === "N/A" || !is_numeric($productPrice) || (float)$productPrice <= 0) {
            throw new Exception("Pricing not available for selected product and hospital tier");
        }

        // Normalize schedule date/time
        $scheduleDate = formatDate($scheduleDate);
        $scheduleTime = formatTime($scheduleTime);

        // Create and save order
		$sql = "INSERT INTO `{$mainDb}`.oxygen_order (`order_by`, payment, qty, product, discount, tym, urgency, order_type, schedule_date, schedule_time, order_state, personnel_name, usage_info, channel, order_source, `unitprice`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
		$js =  R::exec($sql, [$refid, $payment, (int)$qty, $productId, (float)$discount, $createdAt, $urgence, $orderType, $scheduleDate, $scheduleTime, $status, $requester, $usage, $channel, $channelType, (float)$productPrice]);
		$id = R::getInsertID();

        if ($id) {
			notifyLite();
            $payload = [
                'status'   => 'success',
                'message'  => 'Order placed successfully',
                'order_id' => $id,
                'data'     => [
                    'product'  => $productSize,
                    'qty'      => $qty,
                    'price'    => (float)$productPrice,
                    'schedule' => trim(($scheduleDate ?? '') . ' ' . ($scheduleTime ?? ''))
                ]
            ];
            $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        throw new Exception('Order could not be saved');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ], JSON_PRETTY_PRINT));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    } finally {
        R::close();
    }
})->add($authMiddleware);

$app->post('/support', function (Request $request, Response $response) {
    try {
        $parsedBody = $request->getParsedBody() ?? [];

        // Get user info from JWT
        $user = $request->getAttribute('user');
        if (empty($user) || empty($user['ref_id'])) {
            throw new Exception("User authentication failed or missing reference ID");
        }
        $refid = $user['ref_id'];

		// Get and sanitize required fields
		$subject = htmlspecialchars(trim($parsedBody['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
		$category = htmlspecialchars(trim($parsedBody['category'] ?? ''), ENT_QUOTES, 'UTF-8');
		$message = htmlspecialchars(trim($parsedBody['message'] ?? ''), ENT_QUOTES, 'UTF-8');
		
		// Check if empty
		if (empty($subject) || empty($category) || empty($message)) {
			$response->getBody()->write(json_encode([
				'status'  => 'error',
				'message' => 'All fields are required'
			]));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		$support = R::dispense('support');
		$support->subject = $subject;
		$support->category = $category;
		$support->message = $message;
		$support->ref_id = $refid;
		$support->created_at = date('Y-m-d H:i:s');
		$support->updated_at = date('Y-m-d H:i:s');	
		$id = R::store($support);

		if ($id) {
			$response->getBody()->write(json_encode([
				'status'  => 'success',
				'message' => 'Support request submitted successfully'
			]));
			return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
		} else {
			$response->getBody()->write(json_encode([
				'status'  => 'error',
				'message' => 'Support request could not be submitted'
			]));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}
       

    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ], JSON_PRETTY_PRINT));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    } finally {
        R::close();
    }
})->add($authMiddleware);

$requireSupervisorOrToken = function ($request, $response, $next) use ($authService, $authToken) {
	$authHeader = $request->getHeaderLine("Authorization");

	// 1. Check Bearer JWT first
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

	// 2. Check timing-safe token comparison
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

$app->get('/all', function (Request $request, Response $response, array $args) {
	try {
		$hospitals = R::findAll('hospital');
		$return = [
			'status'      => 'success',
			'Description' => 'hospital informations endpoints',
			'Message'     => $hospitals ? 'all the hospitals in the state' : 'no hospital in the state',
			'data'        => $hospitals ? array_values($hospitals) : []
		];

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Hospital list error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'An internal server error occurred']));
	}
})->add($requireSupervisorOrToken);

$app->get('/all/{state}', function (Request $request, Response $response, array $args) {
	try {
		// Sanitize state: strip % and _ to prevent wildcard injection (Item 10)
		$state = str_replace(['%', '_'], '', trim((string)$request->getAttribute('state')));
		if (empty($state)) {
			return $response->withStatus(400)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode(['status' => 'error', 'message' => 'Valid state parameter required']));
		}

		$hospitals = R::find('hospital', ' state = ? ', [$state]);
		$return = [
			'status'      => 'success',
			'Description' => 'hospital informations endpoints',
			'Message'     => $hospitals ? 'all the hospitals in the state' : 'no hospital in the state',
			'data'        => $hospitals ? array_values($hospitals) : []
		];

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Hospital state search error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'An internal server error occurred']));
	}
})->add($requireSupervisorOrToken);

$app->get('/single/{id}', function (Request $request, Response $response, array $args) {
	try {
		$id = (int)$request->getAttribute('id');
		$user = $request->getAttribute('user');

		// Restrict hospital role users to viewing only their own record
		if ($user && ($user['type'] ?? '') === 'hospital' && (int)($user['ref_id'] ?? 0) !== $id) {
			return $response->withStatus(403)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode(['status' => 'error', 'message' => 'Forbidden: Access restricted to your hospital record']));
		}

		$hospital = R::load('hospital', $id);
		if (!$hospital || !$hospital->id) {
			return $response->withStatus(200)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode([
					'status'      => 'false',
					'Description' => 'hospital informations endpoints',
					'Message'     => 'no hospital with that id',
					'data'        => null
				]));
		}

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode([
				'status'      => 'success',
				'Description' => 'hospital informations endpoints',
				'Message'     => 'the hospital with the id',
				'data'        => $hospital
			]));
	} catch (Exception $e) {
		error_log("Single hospital fetch error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'An internal server error occurred']));
	}
})->add($requireSupervisorOrToken);

$app->post('/add', function (Request $request, Response $response) use ($hospitalService) {
	try {
		$params = $request->getParams() ?? [];
		if (empty($params['name']) && empty($params['hos_name'])) {
			return $response->withStatus(400)
				->withHeader('Content-Type', 'application/json')
				->write(json_encode(['status' => 'error', 'message' => 'Hospital name is required']));
		}

		// Use unified HospitalService::createHospital (Item 23)
		$id = $hospitalService->createHospital($params);

		$return = [
			'status'      => 'success',
			'Description' => 'hospital informations endpoints',
			'Message'     => 'hospital was created',
			'data'        => $id
		];

		return $response->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));
	} catch (Exception $e) {
		error_log("Hospital create error: " . $e->getMessage());
		return $response->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode(['status' => 'error', 'message' => 'An internal server error occurred']));
	}
})->add($requireSupervisorOrToken);



$app->run();


function pricing($refid, $productype)
{
	$mainDb = Database::getMainDbName();
	$hospital_info = R::getRow(
		"SELECT `secure_login`.`oxygen_premium`, user_info.state FROM `{$mainDb}`.`secure_login` LEFT JOIN `{$mainDb}`.user_info on user_info.ref_id = secure_login.memberid WHERE memberid = ?",
		[$refid]
	);
	$city = $hospital_info['state'] ?? '';
	$class = $hospital_info['oxygen_premium'] ?? '';

	$product_price = R::getCell(
		"SELECT cost FROM `{$mainDb}`.`pricing` WHERE `product` LIKE ? AND `product_type` LIKE ? AND `city` LIKE ? AND `class` = ?",
		['oxygen', $productype, $city, $class]
	);

	if ($product_price === null || $product_price === '') {
		$product_price = "N/A";
	}

	return $product_price;
}

function formatDate($date)
{
    if (empty($date) || $date === '0000-00-00') return null;
    try {
        $d = new DateTime($date);
        return $d->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

function formatTime($time)
{
    if (empty($time)) return null;
    try {
        $t = new DateTime($time);
        return $t->format('H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function notifyLite()
{
	$mainDb = Database::getMainDbName();
	$time = date('Y-m-d H:i:s');
	R::exec("INSERT INTO `{$mainDb}`.`ordernotify` (`ordertype`, `tym`, `channel`) VALUES (?, ?, ?)", ['Oxygen', $time, 'AirX']);
}
