<?php
/**
 * Принимает события воронки: visit | quiz_open | quiz_submit
 */

declare(strict_types=1);

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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$event = trim((string) ($data['event'] ?? ''));
$vid = isset($data['vid']) ? (string) $data['vid'] : null;
$path = isset($data['path']) ? (string) $data['path'] : null;
$variant = isset($data['variant']) ? (string) $data['variant'] : (isset($_COOKIE['vm_ab_landing_v1']) ? (string) $_COOKIE['vm_ab_landing_v1'] : null);

if (!in_array($event, VM_EVENTS, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Неизвестное событие'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!appendEvent($event, $vid, $path, $variant)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось записать событие'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
