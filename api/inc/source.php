<?php

declare(strict_types=1);

/**
 * @param mixed $utm
 * @return array<string, string>
 */
function cleanLeadUtm(mixed $utm): array
{
    $clean = [];
    if (!is_array($utm)) {
        return $clean;
    }

    foreach ($utm as $key => $value) {
        $key = strtolower(trim((string) $key));
        if (!preg_match('/^utm_[a-z0-9_]{1,32}$/', $key)) {
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($text === '') {
            continue;
        }

        $clean[$key] = mb_substr($text, 0, 120);
    }

    return $clean;
}

function leadSourceName(mixed $utm): string
{
    $clean = cleanLeadUtm($utm);

    return $clean['utm_source'] ?? 'прямой';
}

/**
 * Колонка «Метка»: все utm_* одной строкой. Без меток — пусто.
 *
 * @param mixed $utm
 */
function leadUtmLabel(mixed $utm): string
{
    $clean = cleanLeadUtm($utm);
    if ($clean === []) {
        return '';
    }

    $parts = [];
    foreach ($clean as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    return mb_substr(implode(' · ', $parts), 0, 500);
}

function leadSourceUrlLine(mixed $url): string
{
    $url = is_string($url) ? trim($url) : '';
    $url = str_replace(["\r", "\n"], '', $url);
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        return 'Источник: ' . mb_substr($url, 0, 500);
    }

    return 'Источник: прямой';
}

/**
 * Строки про источник лида для Telegram.
 * Без UTM — «прямой».
 *
 * @param mixed $utm
 * @return list<string>
 */
function leadSourceLines(mixed $utm): array
{
    $labels = [
        'utm_source' => 'Источник',
        'utm_medium' => 'Канал',
        'utm_campaign' => 'Кампания',
        'utm_content' => 'Креатив',
        'utm_term' => 'Ключ',
    ];

    $clean = cleanLeadUtm($utm);

    $lines = [];
    foreach ($labels as $key => $label) {
        if (!isset($clean[$key])) {
            continue;
        }
        $lines[] = $label . ': ' . $clean[$key];
        unset($clean[$key]);
    }

    ksort($clean);
    foreach ($clean as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }

    if ($lines === []) {
        return ['Источник: прямой'];
    }

    return $lines;
}
