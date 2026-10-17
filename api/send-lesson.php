<?php
/**
 * Заявка с /landing/ (видеоурок) → только Google Sheets (без Telegram).
 * Ключи из .env (см. .env.example).
 */

declare(strict_types=1);

require __DIR__ . '/inc/env.php';
require __DIR__ . '/inc/events.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array{ok: bool, http_code: int, body: string, error: string}
 */
function postJson(string $url, array $payload, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_POSTREDIR => 0,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'body' => '', 'error' => $error];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'body' => $body, 'error' => ''];
}

/**
 * @param array{ok: bool, http_code: int, body: string, error: string} $response
 */
function sheetsErrorMessage(array $response, mixed $json): ?string
{
    if (!$response['ok']) {
        return 'Ошибка связи с Google Sheets: ' . $response['error'];
    }

    $httpOk = $response['http_code'] >= 200 && $response['http_code'] < 300;
    $markedOk = is_array($json) && (!empty($json['success']) || !empty($json['ok']));

    if ($markedOk) {
        return null;
    }

    if (is_array($json) && array_key_exists('success', $json) && empty($json['success'])) {
        return (string) ($json['error'] ?? 'Google Sheets error');
    }

    if ($httpOk) {
        return null;
    }

    $hint = $response['http_code'] === 403
        ? ' Проверь деплой Apps Script: Execute as = Me, Who has access = Anyone, и заново Deploy → New version.'
        : '';

    return 'Google Sheets вернул ошибку (HTTP ' . $response['http_code'] . ').' . $hint;
}

function isValidLessonName(string $name): bool
{
    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        return false;
    }

    if (!preg_match("/^[A-Za-zА-Яа-яЁёІіЇїЄєҒғҚқҮүҰұҢңӨөҺһʼ'’.\\-\\s]+$/u", $name)) {
        return false;
    }

    $letters = preg_replace("/[-ʼ'’.\\s]/u", '', $name) ?? '';

    return mb_strlen($letters) >= 2;
}

function normalizeLessonPhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '7' . $digits;
    }

    if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
        $digits = '7' . substr($digits, 1);
    }

    // E.164: код страны + номер, 8–15 цифр, не с нуля
    if (strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
        return null;
    }

    if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
        return '+7 ' . substr($digits, 1, 3) . ' ' . substr($digits, 4, 3) . '-' . substr($digits, 7, 2) . '-' . substr($digits, 9, 2);
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '375')) {
        return '+375 ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 3) . '-' . substr($digits, 8, 2) . '-' . substr($digits, 10, 2);
    }

    return '+' . $digits;
}

$sheetsUrl = (string) (getenv('GOOGLE_SHEETS_WEBHOOK_URL') ?: ($_ENV['GOOGLE_SHEETS_WEBHOOK_URL'] ?? ''));

if ($sheetsUrl === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Заполни GOOGLE_SHEETS_WEBHOOK_URL в файле .env',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = trim(preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')) ?? '');
$phoneRaw = trim((string) ($data['phone'] ?? ''));
$vid = isset($data['vid']) ? (string) $data['vid'] : null;
$variant = isset($data['variant']) ? (string) $data['variant'] : (isset($_COOKIE['vm_ab_landing_v1']) ? (string) $_COOKIE['vm_ab_landing_v1'] : null);

if (!isValidLessonName($name)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажи имя'], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = normalizeLessonPhone($phoneRaw);
if ($phone === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер телефона'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sheetPayload = [
    'type' => 'lesson',
    'name' => $name,
    'phone' => $phone,
    'source' => 'landing',
];

$sheets = postJson($sheetsUrl, $sheetPayload, 60);
$sheetsJson = json_decode($sheets['body'] ?? '', true);
$sheetsError = sheetsErrorMessage($sheets, $sheetsJson);

if ($sheetsError !== null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $sheetsError], JSON_UNESCAPED_UNICODE);
    exit;
}

appendEvent('lead_submit', $vid, '/landing/', $variant);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
