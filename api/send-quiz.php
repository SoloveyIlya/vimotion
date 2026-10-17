<?php
/**
 * Принимает анкету с /quiz/ и отправляет её в Telegram + Google Sheets.
 * Ключи берутся из .env (см. .env.example).
 */

declare(strict_types=1);

require __DIR__ . '/inc/env.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/source.php';

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
        // 302 от Apps Script идём как GET. Повторный POST ещё раз вызывает doPost и двоит строку.
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

    // Apps Script иногда отдаёт HTML после редиректа — 2xx считаем успехом.
    if ($httpOk) {
        return null;
    }

    $hint = $response['http_code'] === 403
        ? ' Проверь деплой Apps Script: Execute as = Me, Who has access = Anyone, и заново Deploy → New version.'
        : '';

    return 'Google Sheets вернул ошибку (HTTP ' . $response['http_code'] . ').' . $hint;
}

function isSheetsValidationError(string $error): bool
{
    return str_contains($error, 'проверки данных') || str_contains($error, 'data validation');
}

/**
 * Google Sheets «список элементов» режет значения по запятой.
 * Для колонки M (Q9) оставляем текст до первой запятой, чтобы запись прошла.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function sheetsPayloadWithoutListCommas(array $payload): array
{
    if (!isset($payload['q9']) || !is_string($payload['q9']) || !str_contains($payload['q9'], ',')) {
        return $payload;
    }

    $payload['q9'] = trim(explode(',', $payload['q9'], 2)[0]);
    return $payload;
}

/**
 * Категория A/B/C по ответам анкеты. Первое совпавшее правило побеждает.
 *
 * C — до 18.
 * B/C — «пока не рассматриваю вложения»: B если доход от $250, иначе C.
 * A — доход от $500, либо $250–500 при официальной работе или своём деле.
 * B — $250–500 без работы и своего дела; $100–250 при работе или своём деле;
 *     доход до $250 без работы или «Не зарабатываю», если готов(а) вложиться.
 * C — тот же низкий доход, если сначала хочет узнать детали.
 *
 * @param array<string, string> $answers id вопроса → ответ
 */
function quizCategory(array $answers): string
{
    $age = trim((string) ($answers['age'] ?? ''));
    $occupation = trim((string) ($answers['occupation'] ?? ''));
    $income = trim((string) ($answers['income'] ?? ''));
    $invest = trim((string) ($answers['invest'] ?? ''));

    if ($age === 'До 18') {
        return 'C';
    }

    $incomeFrom250 = in_array($income, [
        'От 250$ до 500$',
        'От 500$ до 1000$',
        'От 1000$ до 3000$',
        '3000$ и выше',
    ], true);
    $incomeFrom500 = in_array($income, [
        'От 500$ до 1000$',
        'От 1000$ до 3000$',
        '3000$ и выше',
    ], true);
    $hasWork = in_array($occupation, ['Работаю официально', 'Развиваю своё дело'], true);

    if ($invest === 'Пока не рассматриваю вложения') {
        return $incomeFrom250 ? 'B' : 'C';
    }

    if ($incomeFrom500) {
        return 'A';
    }

    if ($income === 'От 250$ до 500$') {
        return $hasWork ? 'A' : 'B';
    }

    if ($income === 'От 100$ до 250$' && $hasWork) {
        return 'B';
    }

    $lowIncomeWithoutWork = ($income === 'От 100$ до 250$' && !$hasWork)
        || $income === 'Не зарабатываю';

    if ($lowIncomeWithoutWork && $invest === 'Да, готов(а) рассмотреть') {
        return 'B';
    }

    return 'C';
}

$botToken = (string) (getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? ''));
$chatId   = (string) (getenv('TELEGRAM_CHAT_ID') ?: ($_ENV['TELEGRAM_CHAT_ID'] ?? ''));
$sheetsUrl = (string) (getenv('GOOGLE_SHEETS_WEBHOOK_URL') ?: ($_ENV['GOOGLE_SHEETS_WEBHOOK_URL'] ?? ''));

if ($botToken === '' || $chatId === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Заполни TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в файле .env',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$contact = $data['contact'] ?? null;
$answers = $data['answers'] ?? null;
$questions = $data['questions'] ?? null;
$vid = isset($data['vid']) ? (string) $data['vid'] : null;
$variant = isset($data['variant']) ? (string) $data['variant'] : (isset($_COOKIE['vm_ab_landing_v1']) ? (string) $_COOKIE['vm_ab_landing_v1'] : null);

if (!is_array($contact) || !is_array($answers) || !is_array($questions)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Нужны поля contact, answers, questions'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = trim(preg_replace('/\s+/u', ' ', (string) ($contact['name'] ?? '')) ?? '');
$phoneRaw = trim((string) ($contact['phone'] ?? ''));
$telegramRaw = trim((string) ($contact['telegram'] ?? ''));

function isValidQuizName(string $name): bool
{
    if ($name === '' || mb_strlen($name) > 80) {
        return false;
    }

    if (!preg_match("/^[A-Za-zА-Яа-яЁёІіЇїЄєҒғҚқҮүҰұҢңӨөҺһʼ'’.\\-\\s]+$/u", $name)) {
        return false;
    }

    $letters = preg_replace("/[-ʼ'’.\\s]/u", '', $name) ?? '';
    return mb_strlen($letters) >= 2;
}

function normalizeQuizPhone(string $phone): ?string
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

function normalizeQuizTelegram(string $telegram): ?string
{
    $handle = trim($telegram);
    if ($handle === '') {
        return null;
    }

    $handle = preg_replace('#^https?://(?:t\.me|telegram\.me)/#i', '', $handle) ?? $handle;
    $handle = preg_replace('#^t\.me/#i', '', $handle) ?? $handle;
    $handle = ltrim($handle, '@');

    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $handle)) {
        return null;
    }

    return '@' . $handle;
}

if (!isValidQuizName($name)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажи имя'], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = normalizeQuizPhone($phoneRaw);
if ($phone === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер телефона'], JSON_UNESCAPED_UNICODE);
    exit;
}

$telegram = normalizeQuizTelegram($telegramRaw);
if ($telegram === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажи ник Telegram'], JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeTgHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Поле заявки: подпись курсивом, ответ обычным текстом.
 */
function quizFieldBlock(string $label, string $value): string
{
    return '<i>' . escapeTgHtml($label) . ':</i> ' . escapeTgHtml($value);
}

$answerValues = [];
$answerById = [];
$fieldBlocks = [];

foreach ($questions as $q) {
    if (!is_array($q)) {
        continue;
    }

    $id = (string) ($q['id'] ?? '');
    $text = trim((string) ($q['text'] ?? ''));
    if ($id === '' || $text === '') {
        continue;
    }

    $answer = $answers[$id] ?? 'нет ответа';
    if (is_array($answer) || is_object($answer)) {
        $answer = json_encode($answer, JSON_UNESCAPED_UNICODE);
    }
    $answer = mb_substr(trim((string) $answer), 0, 2000);
    if ($answer === '') {
        $answer = 'нет ответа';
    }

    $answerValues[] = $answer;
    $answerById[$id] = $answer;

    $hint = trim((string) ($q['hint'] ?? ''));
    $label = $hint !== '' ? $text . ' ' . $hint : $text;
    $fieldBlocks[] = quizFieldBlock(mb_substr($label, 0, 500), $answer);
}

$sourceName = leadSourceName($data['utm'] ?? null);
$category = quizCategory($answerById);
$header = '<b>' . escapeTgHtml(
    "Пользователь из воронки {$sourceName} заполнил заявку, попадает под категорию {$category}"
) . '</b>';

$blocks = array_merge(
    [$header],
    [quizFieldBlock('Имя', $name)],
    $fieldBlocks,
    [
        quizFieldBlock('Ник в Telegram', $telegram),
        quizFieldBlock('Номер телефона', $phone),
    ]
);

$sourceUrl = is_string($data['sourceUrl'] ?? null) ? trim((string) $data['sourceUrl']) : '';
$sourceUrl = str_replace(["\r", "\n"], '', $sourceUrl);
if ($sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl)) {
    $sourceValue = mb_substr($sourceUrl, 0, 500);
} else {
    $sourceValue = 'прямой';
}
$sourceBlock = '<b>Источник:</b> ' . escapeTgHtml($sourceValue);

$message = implode("\n\n", $blocks);
$suffix = "\n\n" . $sourceBlock;
$limit = 4000 - mb_strlen($suffix);
if (mb_strlen($message) > $limit) {
    $message = rtrim(mb_substr($message, 0, max(0, $limit - 1))) . '…';
}
$message .= $suffix;

$sheetPayload = [
    'name' => $name,
    'telegram' => $telegram,
    'phone' => $phone,
    'source' => $sourceValue,
    'label' => leadUtmLabel($data['utm'] ?? null),
    'category' => $category,
];

foreach ($answerValues as $i => $value) {
    $sheetPayload['q' . ($i + 1)] = $value;
}

$sheets = postJson($sheetsUrl, $sheetPayload, 60);
$sheetsJson = json_decode($sheets['body'] ?? '', true);
$sheetsError = sheetsErrorMessage($sheets, $sheetsJson);

// Выпадающий список в колонке M режет «Да, готов(а) рассмотреть» по запятой.
if ($sheetsError !== null && isSheetsValidationError($sheetsError)) {
    $retryPayload = sheetsPayloadWithoutListCommas($sheetPayload);
    if ($retryPayload !== $sheetPayload) {
        $sheets = postJson($sheetsUrl, $retryPayload, 60);
        $sheetsJson = json_decode($sheets['body'] ?? '', true);
        $sheetsError = sheetsErrorMessage($sheets, $sheetsJson);
    }
}

$sheetsOk = $sheetsError === null;

if (!$sheetsOk) {
    $warning = "\n\n⚠️ Таблица: " . escapeTgHtml(mb_substr($sheetsError, 0, 300));
    $limit = 4000 - mb_strlen($warning);
    if (mb_strlen($message) > $limit) {
        $message = rtrim(mb_substr($message, 0, max(0, $limit - 1))) . '…';
    }
    $message .= $warning;
}

$tg = postJson('https://api.telegram.org/bot' . $botToken . '/sendMessage', [
    'chat_id' => $chatId,
    'text' => $message,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
]);

if (!$tg['ok']) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Ошибка связи с Telegram: ' . $tg['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$tgJson = json_decode($tg['body'], true);
if ($tg['http_code'] >= 400 || !is_array($tgJson) || empty($tgJson['ok'])) {
    $desc = is_array($tgJson) ? (string) ($tgJson['description'] ?? 'Telegram API error') : 'Telegram API error';
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $desc], JSON_UNESCAPED_UNICODE);
    exit;
}

appendEvent('quiz_submit', $vid, '/quiz/', $variant);
appendEvent('lead_submit', $vid, '/quiz/', $variant);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
