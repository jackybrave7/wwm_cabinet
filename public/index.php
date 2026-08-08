<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($method === 'HEAD') {
    ob_start();
    (new Wwm\Router())->dispatch('GET', $uri);
    ob_end_clean();
    return;
}

(new Wwm\Router())->dispatch($method, $uri);
