<?php
declare(strict_types=1);

/**
 * router.php: front controller for the PHP built-in web server:
 *   php -S 0.0.0.0:8080 -t ui ui/router.php
 * Serves static assets directly, falls through to PHP pages.
 * Under Apache/nginx with docroot = ui/ this file is simply unused.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__;

// Normalize and block path traversal.
$path = realpath($root . $uri);
if ($path !== false && ($path === $root || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0)) {
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            require $path; // let the built-in server semantics apply
            return;
        }
        return false; // static file: built-in server serves it
    }
    if (is_dir($path)) {
        $index = $path . '/index.php';
        if (is_file($index)) {
            require $index;
            return;
        }
    }
}

if ($uri === '/' || $uri === '') {
    require $root . '/index.php';
    return;
}

http_response_code(404);
header('Content-Type: text/plain');
echo '404 Not Found';
