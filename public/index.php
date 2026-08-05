<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

(new Wwm\Router())->dispatch($method, $uri);
