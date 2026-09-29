<?php 

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


require '../vendor/autoload.php';

if (class_exists('Dotenv\Dotenv')) {
	$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
	$dotenv->safeLoad();
}

$authToken = $_ENV['AUTH_TOKEN'] ?? $_ENV['authtoken'] ?? 'test';

$app = new \Slim\App;

//welcome to AirX	
$app->get('/', function (Request $request, Response $response) use ($authToken) {
	
	$authorization_header = $request->getHeader("Authorization");
	
	if(empty($authorization_header) || ($authorization_header[0] != $authToken)){ 
		
	    $return =  array('status'=> 'false' , 'Description' =>'Welcome to AirX API', 'Message' =>'Header is missing','data'=>null); 
	   
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
	
	if(empty($authorization_header) || ($authorization_header[0] != $authToken)){ 
		
	    $return =  array('status'=> 'false' , 'Description' =>'Welcome to AirX API', 'Message' =>'Header is missing','data'=>null); 
	   
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

