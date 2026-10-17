<?php
/**
 * Роутер для `php -S 127.0.0.1:8000 router.php`
 * На Apache срабатывает .htaccess.
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($uri);
$qs = $_SERVER['QUERY_STRING'] ?? '';
$suffix = $qs !== '' ? '?' . $qs : '';

function vm_redirect(string $to): void
{
    header('Location: ' . $to, true, 301);
    exit;
}

if (in_array($path, ['/landing', '/landing/index.php', '/landing/index.html', '/landing/a.html', '/landing/b.html'], true)) {
    $params = [];
    parse_str($qs, $params);
    if ($path === '/landing/a.html') {
        $params['ab_variant'] = 'A';
    }
    if ($path === '/landing/b.html') {
        $params['ab_variant'] = 'B';
    }
    $to = '/landing/';
    if ($params) {
        $to .= '?' . http_build_query($params);
    }
    vm_redirect($to);
}

if ($path === '/landing/') {
    require __DIR__ . '/landing/index.php';
    return true;
}

$full = __DIR__ . $path;
if ($path !== '/' && is_file($full)) {
    return false;
}

if (is_dir($full)) {
    if ($path !== '/' && substr($path, -1) !== '/') {
        vm_redirect($path . '/' . $suffix);
    }
    if (is_file($full . '/index.php')) {
        require $full . '/index.php';
        return true;
    }
    if (is_file($full . '/index.html')) {
        return false;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
return true;
