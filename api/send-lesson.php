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

/**
 * Контакт в Sheets — строка (телефон / Telegram / email с варианта B).
 * Формат проверяет клиент; сервер только принимает непустую строку.
 */
function normalizeLessonContact(array $data): ?string
{
    $contact = trim((string) ($data['phone'] ?? $data['contact'] ?? ''));
    if ($contact === '') {
        $contact = trim((string) ($data['email'] ?? ''));
    }

    if ($contact === '' || strlen($contact) > 120) {
        return null;
    }

    return $contact;
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
$vid = isset($data['vid']) ? (string) $data['vid'] : null;
$variant = isset($data['variant']) ? (string) $data['variant'] : (isset($_COOKIE['vm_ab_landing_v1']) ? (string) $_COOKIE['vm_ab_landing_v1'] : null);

if (!isValidLessonName($name)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажи имя'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contact = normalizeLessonContact($data);
if ($contact === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажи Telegram или телефон'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sheetPayload = [
    'type' => 'lesson',
    'name' => $name,
    'phone' => $contact,
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
