<?php

declare(strict_types=1);

const VM_EVENTS = ['visit', 'quiz_open', 'quiz_submit', 'landing_view', 'cta_click', 'lesson_play', 'lead_submit'];

function eventsDataDir(): string
{
    return dirname(__DIR__, 2) . '/data';
}

function eventsLogPath(): string
{
    return eventsDataDir() . '/events.jsonl';
}

function eventSubstr(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function appendEvent(string $event, ?string $vid = null, ?string $path = null, ?string $variant = null): bool
{
    if (!in_array($event, VM_EVENTS, true)) {
        return false;
    }

    $vid = $vid !== null ? trim($vid) : '';
    $vid = $vid !== '' ? eventSubstr($vid, 64) : null;

    $path = $path !== null ? trim($path) : '';
    $path = $path !== '' ? eventSubstr($path, 200) : null;

    $variant = strtoupper(trim((string) $variant));
    if (!in_array($variant, ['A', 'B'], true)) {
        $variant = null;
    }

    $dir = eventsDataDir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }

    $row = json_encode([
        'ts' => gmdate('c'),
        'event' => $event,
        'vid' => $vid,
        'path' => $path,
        'variant' => $variant,
    ], JSON_UNESCAPED_UNICODE);

    if ($row === false) {
        return false;
    }

    return file_put_contents(eventsLogPath(), $row . "\n", FILE_APPEND | LOCK_EX) !== false;
}

/**
 * @return list<array{ts: string, event: string, vid: ?string, path: ?string, variant: ?string}>
 */
function readEvents(): array
{
    $file = eventsLogPath();
    if (!is_readable($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['event']) || !is_string($row['event'])) {
            continue;
        }
        if (!in_array($row['event'], VM_EVENTS, true)) {
            continue;
        }
        $out[] = [
            'ts' => (string) ($row['ts'] ?? ''),
            'event' => $row['event'],
            'vid' => isset($row['vid']) && is_string($row['vid']) ? $row['vid'] : null,
            'path' => isset($row['path']) && is_string($row['path']) ? $row['path'] : null,
            'variant' => isset($row['variant']) && in_array(strtoupper((string) $row['variant']), ['A', 'B'], true) ? strtoupper((string) $row['variant']) : null,
        ];
    }

    return $out;
}
