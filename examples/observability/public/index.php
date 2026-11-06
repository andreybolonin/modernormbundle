<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use ModernORM\Bundle\Example\Observability\Kernel;
use Symfony\Component\HttpFoundation\Request;

$kernel = new Kernel();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
$kernel->terminate($request, $response);
$kernel->shutdown();
