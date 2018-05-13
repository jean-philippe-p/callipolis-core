<?php
use Comhon\Api\ObjectService;
use Comhon\Object\Config\Config;
use Comhon\Model\Singleton\ModelManager;
use Comhon\Interfacer\StdObjectInterfacer;
use Callipolis\Exception\HttpException;

require_once 'vendor/autoload.php';



$base_path = '/home/jean-philippe/ReposGit/callipolis/src/';

Config::setLoadPath("./config/config.json");


/************************************************\
|                   functions                    |
\************************************************/

function getMainServices() {
    $params = new \stdClass();
    $params->model = 'MainService';
    
    // hack, objectservice doesn't work without filter so we add fake one
    $params->filter = getFilter();
    
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
        throw new HttpException('error', 500);
    }
    return $res->result;
}

function getMainService($mainServiceId) {
    if (!ctype_digit($mainServiceId)) {
        throw new HttpException('error', 400);
    }
    $mainServiceModel = ModelManager::getInstance()->getInstanceModel('MainService');
    $mainService = $mainServiceModel->loadObject((integer) $mainServiceId);
    if (is_null($mainService)) {
        throw new HttpException('error', 404);
    }
    return $mainService->export(new StdObjectInterfacer());
}

function getSubServices($mainServiceId) {
    $params = new \stdClass();
    $params->model = 'SubService';
    $params->filter = getFilter('SubService', 'mainService', '=', $mainServiceId);
    $params->properties = ['title', 'summary', 'mainService', 'logo', 'color'];
    
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
        throw new HttpException('error', 500);
    }
    return $res->result;
}

function getSubService($subServiceId) {
    if (!ctype_digit($subServiceId)) {
        throw new HttpException('error', 400);
    }
    $subServiceModel = ModelManager::getInstance()->getInstanceModel('SubService');
    $subService = $subServiceModel->loadObject((integer) $subServiceId);
    if (is_null($subService)) {
        throw new HttpException('error', 404);
    }
    return $subService->export(new StdObjectInterfacer());
}

function getNavBar() {
    $navbar = new \stdClass();
    $params = new \stdClass();
    $params->model = 'MainService';
    $params->properties = ['title'];
    
    // hack, objectservice doesn't work without filter so we add fake one
    $params->filter = getFilter();
    
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
        throw new HttpException('error', 500);
    }
    $navbar->services = [];
    $propertySubServices = ModelManager::getInstance()->getInstanceModel('MainService')->getProperty('subServices', true)->getName();
    foreach ($res->result as $mainService) {
        $navbar->services[$mainService->id] = $mainService;
        $navbar->services[$mainService->id]->{$propertySubServices} = [];
    }
    
    $params->model = 'SubService';
    $params->properties = ['title', 'mainService'];
    $res = ObjectService::getObjects($params);
    if (!$res->success) {
        throw new HttpException('error', 500);
    }
    $propertyMainService = ModelManager::getInstance()->getInstanceModel('SubService')->getProperty('mainService', true)->getName();
    foreach ($res->result as $subService) {
        $navbar->services[$subService->{$propertyMainService}]->$propertySubServices[] = $subService;
    }
    $navbar->services = array_values($navbar->services);
    return $navbar;
}

function getFilter($model = 'MainService', $property = 'title', $operator = '<>', $value = 'plop') {
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
    $file_af = "/var/data/image/$logoId/image.png";
    
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
        case 'MainServices':
            $response = getMainServices();
            break;
        case 'MainService':
            $response = getMainService($explodedRoute[1]);
            break;
        case 'SubServices':
            $response = getSubServices($explodedRoute[1]);
            break;
        case 'SubService':
            $response = getSubService($explodedRoute[1]);
            break;
        case 'Logo':
            header('Content-Type: image/png');
            getLogo($explodedRoute[1]);
            $isFile = true;
            break;
        default:
            throw new HttpException('error', 501);
            break;
    }
    if (!$isFile) {
        if (!is_array($response) && !($response instanceof \stdClass)) {
            throw new HttpException('error', 500);
        }
        header('Content-Type: application/json');
        echo json_encode($response);
    }
}

/************************************************\
|                     main                       |
\************************************************/

$route = substr(preg_replace('~/+~', '/', $_SERVER['REQUEST_URI'].'/'), 1, -1);

//echo $route."<br/>";

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

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            get($explodedRoute);
            break;
        default:
            throw new HttpException('error', 501);
            break;
    }    
} catch (HttpException $e) {
    http_response_code($e->getCode());
} catch (Exception $e) {
    http_response_code(500);
    trigger_error($e->getCode());
    trigger_error($e->getMessage());
}
