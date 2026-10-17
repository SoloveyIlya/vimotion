<?php
/**
 * A/B лендинг на одном URL: /landing/
 * Вариант липкий в cookie 180 дней. Превью: ?ab_variant=A|B
 */
declare(strict_types=1);

const VM_AB_COOKIE = 'vm_ab_landing_v1';
const VM_AB_TTL = 15552000; // 180 days

function vm_ab_valid(?string $value): ?string
{
    $value = strtoupper(trim((string) $value));
    return ($value === 'A' || $value === 'B') ? $value : null;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/landing' || preg_match('#/landing/index\.php$#', $path) === 1) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /landing/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

$forced = vm_ab_valid($_GET['ab_variant'] ?? null);
$stored = vm_ab_valid($_COOKIE[VM_AB_COOKIE] ?? null);
$variant = $forced ?? $stored ?? (random_int(0, 1) === 0 ? 'A' : 'B');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
header('Vary: Cookie');
header('X-AB-Variant: ' . $variant);

setcookie(VM_AB_COOKIE, $variant, [
    'expires' => time() + VM_AB_TTL,
    'path' => '/',
    'secure' => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
]);

$file = __DIR__ . ($variant === 'B' ? '/b.html' : '/a.html');
$html = file_get_contents($file);
if ($html === false) {
    http_response_code(500);
    echo 'Landing variant is missing.';
    exit;
}

$boot = '<script>window.vmAbVariant=' . json_encode($variant, JSON_UNESCAPED_SLASHES) . ';</script>';
$html = preg_replace('/<html\b/i', '<html data-ab-variant="' . $variant . '"', $html, 1) ?? $html;
$html = preg_replace('/<head([^>]*)>/i', '<head$1>' . "\n  " . $boot, $html, 1) ?? $html;

$pixel = 'https://www.facebook.com/tr?id=1638653997627566&ev=PageView&noscript=1&cd[ab_variant]=' . $variant . '&cd[content_name]=landing_' . $variant;
$html = preg_replace(
    '#https://www\.facebook\.com/tr\?id=1638653997627566&ev=PageView&noscript=1#',
    $pixel,
    $html,
    1
) ?? $html;

echo $html;
