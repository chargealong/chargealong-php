<?php

declare(strict_types=1);

// Router for `php -S`, driven by CurlTransportTest.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/echo':
        header('Content-Type: application/json');
        header('X-Trace: abc');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'query' => $_SERVER['QUERY_STRING'] ?? '',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
        ]);
        break;
    case '/redirect':
        header('Location: /echo', true, 302);
        break;
    case '/problem':
        http_response_code(429);
        header('Retry-After: 7');
        echo '{"title":"slow down"}';
        break;
    case '/big':
        header('Content-Type: application/octet-stream');
        $chunk = str_repeat('x', 1 << 20);
        for ($i = 0; $i < 9; $i++) {
            echo $chunk;
            flush();
        }
        break;
    default:
        http_response_code(404);
}
