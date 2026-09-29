<?php 

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


require '../vendor/autoload.php';

if (class_exists('Dotenv\Dotenv')) {
	$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
	$dotenv->safeLoad();
}

// Action B2: enforce strong AUTH_TOKEN
$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? '';
if (strlen($authToken) < 24 || $authToken === 'test') {
	throw new RuntimeException('AUTH_TOKEN must be set to a long random value');
}

$app = new \Slim\App;

//welcome to AirX	
$app->get('/', function (Request $request, Response $response) use ($authToken) {
	
	$authorization_header = $request->getHeader("Authorization");
	
	if(empty($authorization_header) || !hash_equals((string)$authToken, (string)$authorization_header[0])){ 
		
	    $return =  array('status'=> 'false' , 'Description' =>'Welcome to AirX API', 'Message' =>'Header is missing or invalid','data'=>null); 
	   
	    return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));

	}
	
    $return =  array('status'=> 'success' , 'Description' =>'Welcome to AirX API', 'Message' =>'Welcome to AirX API','data'=>null); 
	   
    return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));

});

// AirX	API Help
$app->get('/help', function (Request $request, Response $response) use ($authToken) {
	
	$authorization_header = $request->getHeader("Authorization");
	
	if(empty($authorization_header) || !hash_equals((string)$authToken, (string)$authorization_header[0])){ 
		
	    $return =  array('status'=> 'false' , 'Description' =>'Welcome to AirX API', 'Message' =>'Header is missing or invalid','data'=>null); 
	   
	    return $response->withStatus(401)
			->withHeader('Content-Type', 'application/json')
			->write(json_encode($return));

	}
	
    $return =  array('status'=> 'success' , 'Description' =>'AirX API Help', 'Message' =>null,'data'=>null); 
	   
    return $response->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($return));
   
});

$app->run();

