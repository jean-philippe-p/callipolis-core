<?php
use Comhon\Api\ObjectService;
use Comhon\Object\Config\Config;
use Comhon\Model\Singleton\ModelManager;
use Comhon\Interfacer\StdObjectInterfacer;
use Callipolis\Exception\HttpException;
use Comhon\Interfacer\AssocArrayInterfacer;
use Comhon\Object\Object;
use Comhon\Serialization\SqlTable;
use Comhon\Database\DatabaseController;
use Comhon\Model\ModelArray;
use Comhon\Model\SimpleModel;
use Comhon\Model\Property\ForeignProperty;
use Comhon\Interfacer\AssocArrayNoScalarTypedInterfacer;
use Comhon\Model\MainModel;
use Comhon\Model\ModelInteger;

require_once 'vendor/autoload.php';


$base_path = '/home/jean-philippe/ReposGit/callipolis/src/';

Config::setLoadPath("./config/config.json");


/************************************************\
|                   functions                    |
\************************************************/


/**
 * 
 * @param string $modelName
 * @param integer $pagination
 * @param string[] $properties
 * @throws HttpException
 * @return unknown
 */
function getResources($modelName, $pagination = null, $properties = null) {
	$get = $_GET;
	$model = ModelManager::getInstance()->getInstanceModel($modelName);
	$params = new \stdClass();
	$params->model = $modelName;
	
	// properties to retrieve
	$tempProperties = [];
	if (isset($get['properties'])) {
		$requestProperties = json_decode($get['properties']);
		if (is_null($properties)) {
			$tempProperties = $requestProperties;
		} else {
			foreach ($requestProperties as $requestProperty) {
				if (in_array($requestProperty, $properties)) {
					$tempProperties[] = $requestProperty;
				}
			}
		}
		unset($get['properties']);
	}
	if (count($tempProperties) > 0) {
		$params->properties = $tempProperties;
	}elseif (!is_null($properties)) {
		$params->properties = $properties;
	}
	
	// limit and offset
	if (!is_null($pagination)) {
		$params->maxLength = 10;
		$params->offset= $pagination * 10;
	}
	
	// values order
	if (isset($get['order'])) {
		$params->order = json_decode($get['order']);
		if (!is_array($params->order)) {
			throw new HttpException("malformed order", 400);
		}
		unset($get['order']);
	}
	
	// set filter to apply
	$filters = [];
	$interfacer = new AssocArrayNoScalarTypedInterfacer();
	foreach ($get as $propertyName => $value) {
		$property = $model->getProperty($propertyName);
		if (is_null($property)) {
			throw new HttpException("Unknown property $propertyName", 400);
		}
		if (substr($value, 0, 1) === '[') {
			$tempValue = json_decode($value);
			if ($tempValue !== null && $tempValue !== false) {
				$value = $tempValue;
			}
			
		}
		if (!($property instanceof ForeignProperty)) {
			if (!($property->getModel() instanceof SimpleModel)) {
				throw new HttpException("Not supported property $propertyName", 400);
			}
			if (is_array($value)) {
				foreach ($value as &$subValues) {
					$subValues = $property->getModel()->importSimple($subValues, $interfacer);
				}
			} else {
				$value = $property->getModel()->importSimple($value, $interfacer);
			}
		}
		$filters[] = getFilter($modelName, $propertyName, '=', $value);
		
	}
	if (count($filters) === 0) {
		// hack comhon doesn't work without filter
		$properties = $model->getProperties();
		$params->filter = new \stdClass();
		$params->filter->type = 'disjunction';
		$params->filter->elements = [
				getFilter($modelName, current($properties)->getName(), '=', null),
				getFilter($modelName, current($properties)->getName(), '<>', null)
		];
	} elseif (count($filters) === 1) {
		$params->filter = $filters[0];
	} else {
		$params->filter = new \stdClass();
		$params->filter->type = 'conjunction';
		$params->filter->elements = $filters;
	}
	$res = ObjectService::getObjects($params);
	if (!$res->success) {
		throw new HttpException(json_encode($res), 500);
	}
	return $res->result;
}

function getResource($resource, $id) {
    $model = ModelManager::getInstance()->getInstanceModel($resource);
    $idProperties = $model->getIdProperties();
    if (count($idProperties) !== 1) {
    	throw new HttpException('id property must be unique', 400);
    }
    if (current($idProperties)->getModel() instanceof ModelInteger) {
    	if (!ctype_digit($id)) {
    		throw new HttpException('id must be an integer', 400);
    	}
    	$id = (integer) $id;
    }
    $object = $model->loadObject($id);
    if (is_null($object)) {
    	throw new HttpException("$resource $id not found", 404);
    }
    return $object->export(new StdObjectInterfacer());
}

function getNavBar() {
	$navbar = new \stdClass();
	
	// retrieve services and sub-services
    $params = new \stdClass();
    $params->model = 'MainService';
    $params->properties = ['title'];
    $params->filter = getFilter('MainService', 'available', '=', true);
    
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
    	throw new HttpException(json_encode($res), 500);
    }
    $navbar->services = [];
    $propertySubServices = ModelManager::getInstance()->getInstanceModel('MainService')->getProperty('subServices', true)->getName();
    foreach ($res->result as $mainService) {
        $navbar->services[$mainService->id] = $mainService;
        $navbar->services[$mainService->id]->{$propertySubServices} = [];
    }
    
    $params = new \stdClass();
    $params->model = 'SubService';
    $params->properties = ['title', 'mainService', 'keyWords'];
    $params->filter = getFilter('SubService', 'available', '=', true);
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
    	throw new HttpException(json_encode($res), 500);
    }
    $propertyMainService = ModelManager::getInstance()->getInstanceModel('SubService')->getProperty('mainService', true)->getName();
    foreach ($res->result as $subService) {
    	if (isset($navbar->services[$subService->{$propertyMainService}])) {
        	$navbar->services[$subService->{$propertyMainService}]->$propertySubServices[] = $subService;
    	}
    }
    $navbar->services = array_values($navbar->services);
    
    // retrieve introduces
    $params = new \stdClass();
    $params->model = 'Introduce';
    $params->properties = ['title', 'display'];
    
    $display = ['navbar'];
    if (isset($_GET['display'])) {
    	$display = array_merge($display, json_decode($_GET['display']));
    }
    $params->filter = getFilter('Introduce', 'display', '=', $display);
    
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
    	throw new HttpException(json_encode($res), 500);
    }
    $navbar->introduces = $res->result;
    
    // retrieve carousel
    if (isset($_GET['carousel']) && $_GET['carousel'] === 'true') {
    	$params = new \stdClass();
    	$params->model = 'CarouselPart';
    	$params->properties = ['title'];
    	$params->filter = getFilter('CarouselPart', 'id', '<>', 0);
    	
    	$res = ObjectService::getObjects($params);
    	if (!$res->success) {
    		throw new HttpException(json_encode($res), 500);
    	}
    	$navbar->carousel = $res->result;
    }
    
    // retrieve articles
    if (isset($_GET['articles']) && $_GET['articles'] === 'true') {
    	$params = new \stdClass();
    	$params->model = 'Article';
    	$params->properties = ['numero', 'type'];
    	$params->filter = getFilter('Article', 'id', '<>', 0);
    	
    	$orderType = new stdClass();
    	$orderType->property = 'type';
    	$orderType->type= 'ASC';
    	$params->order = [$orderType];
    	
    	$res = ObjectService::getObjects($params);
    	if (!$res->success) {
    		throw new HttpException(json_encode($res), 500);
    	}
    	$navbar->articles = $res->result;
    }
    
    return $navbar;
}

function getTowns() {
	if (!isset($_GET['search']) || strlen($_GET['search']) < 3) {
		throw new HttpException("lissing or malformed search", 400);
	}
	$database = DatabaseController::getInstanceWithDataBaseId('1');
	$query =  'SELECT * FROM town WHERE name LIKE ? OR code_postal LIKE ?;';
	$statement = $database->executeSimpleQuery($query, [$_GET['search'].'%', $_GET['search'].'%']);
	$interfacer = new AssocArrayInterfacer();
	
	$model = ModelManager::getInstance()->getInstanceModel('Town');
	$modelArray = new ModelArray($model, 'town');
	$interfacer->setSerialContext(true);
	$rows = $statement->fetchAll();
	SqlTable::castStringifiedColumns($rows, $model);
	$objects = $modelArray->import($rows, $interfacer);
	$interfacer->setSerialContext(false);
	return $interfacer->export($objects);
}

function startAdminSession() {
	$post = json_decode(file_get_contents('php://input'), true);
	
	$params = new \stdClass();
	$params->model = 'Admin';
	$params->filter = new \stdClass();
	$params->filter->type = 'conjunction';
	$params->filter->elements = [
		getFilter('Admin', 'name', '=', $post['name']),
		getFilter('Admin', 'password', '=', $post['password']),
	];
	
	$res = ObjectService::getObjects($params);
	if (!$res->success) {
		throw new HttpException(json_encode($res), 500);
	}
	if (count($res->result) === 0) {
		throw new HttpException('not found', 404);
	}
	if (count($res->result) > 1) {
		throw new HttpException('should\'t have several result', 500);
	}
	
	$admin = ModelManager::getInstance()->getInstanceModel('Admin')->loadObject($res->result[0]->name);
	$admin->setValue('token', md5(mt_rand()));
	$admin->save(SqlTable::UPDATE);
	$token = new \stdClass();
	$token->token = $admin->getValue('token');
	
	return $token;
}

function getFilter($model, $property, $operator, $value) {
    $filter = new stdClass();
    $filter->model = $model;
    $filter->property = $property;
    $filter->operator = $operator;
    $filter->value = $value;
    
    return $filter;
}

function getLogo($logoId) {
    if (!ctype_digit($logoId)) {
        throw new HttpException('error', 400);
    }
    $file_af = __DIR__ . "/config/image/$logoId/image.png";
    
    if (!file_exists($file_af)) {
        throw new HttpException('error', 404);
    }
    readfile($file_af);
}

function get($explodedRoute) {
    $response = null;
    $isFile = false;
    
    switch ($explodedRoute[0]) {
        case 'Navbar':
            $response = getNavBar();
            break;
        case 'Towns':
        	$response = getTowns();
        	break;
        case 'MainServices':
        	$response = getResources('MainService', getPagination($explodedRoute));
        	break;
        case 'Contacts':
        	$response = getResources('Contact', getPagination($explodedRoute));
        	break;
        case 'SubServices':
        	$response = getResources('SubService', getPagination($explodedRoute), ['title', 'summary', 'mainService', 'logo', 'color']);
        	break;
        case 'Introduces':
        	$response = getResources('Introduce', getPagination($explodedRoute), ['title', 'display', 'text']);
            break;
        case 'Carousel':
        	$response = getResources('CarouselPart', getPagination($explodedRoute));
            break;
        case 'Articles':
        	$response = getResources('Article', getPagination($explodedRoute));
        	break;
        case 'MainService':
        case 'SubService':
        case 'Introduce':
        case 'Company':
        case 'CarouselPart':
        case 'Article':
        	$response = getResource($explodedRoute[0], urldecode($explodedRoute[1]));
            break;
        case 'Logo':
            header('Content-Type: image/png');
            getLogo($explodedRoute[1]);
            $isFile = true;
            break;
        default:
            throw new HttpException('route not handled', 501);
            break;
    }
    if (!$isFile) {
        if (!is_array($response) && !($response instanceof \stdClass)) {
        	throw new HttpException('malformed response', 500);
        }
        header('Content-Type: application/json');
        echo json_encode($response);
    }
}

function getPagination($explodedRoute) {
	$pagination = null;
	if (isset($explodedRoute[1])) {
		if (!ctype_digit($explodedRoute[1])) {
			throw new HttpException('malformed pagination', 400);
		}
		$pagination = (integer) $explodedRoute[1];
	}
	return $pagination;
}

function post($explodedRoute) {
	$publicPost = ['Contact'];
	
	if (!in_array($explodedRoute[0], $publicPost)) {
		validateToken();
	}
	
	$response = null;
	$isFile = false;
	$post = json_decode(file_get_contents('php://input'), true);
	
	$model  = ModelManager::getInstance()->getInstanceModel($explodedRoute[0]);
	$interfacer = new AssocArrayInterfacer();
	$interfacer->setFlagObjectAsLoaded(false);
	
	/**
	 * @var \Comhon\Object\Object $object
	 */
	$object = $interfacer->import($post, $model);
	
	if ($object->hasCompleteId()) {
		$idProperties = [];
		foreach ($model->getIdProperties() as $property) {
			$idProperties[] = $property->getName();
		}
		if ($model->getSerialization() instanceof SqlTable) {
			$code = is_null($model->loadObject($object->getId(), $idProperties)) ? 201 : 200;
			$operation = $code === 201 ? SqlTable::CREATE : SqlTable::UPDATE;
		} else {
			$code = 200;
			$operation = null;
		}
	} else {
		$code = 201;
		$operation = SqlTable::CREATE;
	}
	
	if ($object->save($operation) === 0 && $code === 201) {
		throw new HttpException('malformed request', 400);
	}
	$model->loadAndFillObject($object, null, true);
	
	header('Content-Type: application/json');
	http_response_code($code);
	echo json_encode($interfacer->export($object));
}

function delete($explodedRoute) {
	validateToken();
	$handled = ['CarouselPart'];
	if (!isset($explodedRoute[0]) || !isset($explodedRoute[1])) {
		throw new HttpException('id must be an integer', 400);
	}
	$modelName = $explodedRoute[0];
	$id = $explodedRoute[1];
	if (!in_array($modelName, $handled)) {
	    throw new HttpException('delete not handled for model '.$modelName, 501);
	}
	if (!ctype_digit($id)) {
		throw new HttpException('id must be an integer', 400);
	}
	$id = (integer) $id;
	$model  = ModelManager::getInstance()->getInstanceModel($modelName);
	
	$idProperties = [];
	foreach ($model->getIdProperties() as $property) {
		$idProperties[] = $property->getName();
	}
	$object = $model->loadObject($id, $idProperties);
	if (is_null($object)) {
		throw new HttpException("{$model->getName()} with id '{$id} not found", 404);
	}
	$object->delete();
	
	header('Content-Type: application/json');
	$interfacer = new AssocArrayInterfacer();
	echo json_encode($interfacer->export($object));
}

function validateToken() {
	if (!array_key_exists('token', $_GET)) {
		throw new HttpException('missing token', 401);
	}
	
	$params = new \stdClass();
	$params->model = 'Admin';
	$params->filter = getFilter('Admin', 'token', '=', $_GET['token']);
	
	$res = ObjectService::getObjects($params);
	if (!$res->success) {
		throw new HttpException(json_encode($res), 500);
	}
	if (count($res->result) === 0) {
		throw new HttpException('invalid token', 401);
	}
}

/************************************************\
|                     main                       |
\************************************************/

$route = substr(preg_replace('~/+~', '/', $_SERVER['REQUEST_URI'].'/'), 1, -1);
if (strpos($route, '?') !== false) {
	$route = strstr($route, '?', true);
}

// trigger_error($route);

$explodedRoute = explode('/', $route);
$prefixAPI = array_shift($explodedRoute);

if ($prefixAPI !== 'api') {
    http_response_code(404);
    exit(0);
}
if (empty($explodedRoute)) {
    http_response_code(200);
    exit(0);
}

// TODO remove
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');

try {
	if ($explodedRoute[0] == 'upload') {
		validateToken();
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
			exit(0);
		}
		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			throw new HttpException('method not handled', 501);
		}
		if (empty($_FILES['logo'])) {
			throw new HttpException('missing file', 400);
		}
		if ($_FILES['logo']['error'] !== 0) {
			throw new HttpException('upload error', 500);
		}
		$id = time().mt_rand();
		$logo_ad = __DIR__ . '/config/image/' . $id;
		$logo_af = $logo_ad. '/image.png';
		if (!mkdir($logo_ad) || !move_uploaded_file($_FILES['logo']['tmp_name'], $logo_af)) {
			throw new HttpException('upload copy error', 500);
		}
		echo json_encode(['id' => $id]);
	} elseif ($explodedRoute[0] == 'login') {
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
			exit(0);
		}
		if ($_SERVER['REQUEST_METHOD'] != 'POST') {
			throw new HttpException('method not handled', 501);
		}
		echo json_encode(startAdminSession());
	} else {
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'GET':
				get($explodedRoute);
				break;
			case 'POST':
				post($explodedRoute);
				break;
			case 'DELETE':
				delete($explodedRoute);
				break;
			case 'OPTIONS':
				header('Content-Type: application/json');
				break;
	        default:
	            throw new HttpException('method not handled', 501);
	            break;
	    }
	}
} catch (HttpException $e) {
	http_response_code($e->getCode());
	trigger_error($e->getCode());
	trigger_error($e->getMessage());
	trigger_error($e->getTraceAsString());
	file_put_contents('./error.log', $e->getMessage()."\n", FILE_APPEND);
} catch (Exception $e) {
    http_response_code(500);
    trigger_error($e->getCode());
    trigger_error($e->getMessage());
    trigger_error($e->getTraceAsString());
    file_put_contents('./error.log', $e->getMessage()."\n", FILE_APPEND);
}
