<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestPath = rawurldecode($requestPath ?: '/');

$segments = [];
foreach (explode('/', str_replace('\\', '/', $requestPath)) as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }

    if ($segment === '..') {
        array_pop($segments);
        continue;
    }

    $segments[] = $segment;
}

$requestPath = '/' . implode('/', $segments);

// Match the Nginx restrictions and keep repository metadata private.
if (
    preg_match('#^/(?:data|cron)(?:/|$)#i', $requestPath)
    || preg_match('#(?:^|/)\.[^/]+(?:/|$)#', $requestPath)
) {
    http_response_code(404);
    exit;
}

// Serve the customer portal from its canonical extensionless URL.
if ($requestPath === '/pelanggan') {
    require __DIR__ . '/pelanggan.php';
    return;
}

$requestedFile = __DIR__ . $requestPath;

if ($requestPath !== '/' && (is_file($requestedFile) || is_dir($requestedFile))) {
    return false;
}

require __DIR__ . '/index.php';
