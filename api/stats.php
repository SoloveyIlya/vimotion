<?php
/**
 * Внутренняя воронка: посетитель → анкета → отправка.
 * Доступ: ?key=STATS_KEY из .env
 */

declare(strict_types=1);

require __DIR__ . '/inc/env.php';
require __DIR__ . '/inc/events.php';

header('X-Robots-Tag: noindex, nofollow');

$statsKey = (string) (getenv('STATS_KEY') ?: ($_ENV['STATS_KEY'] ?? ''));
$given = (string) ($_GET['key'] ?? '');

if ($statsKey === '' || !hash_equals($statsKey, $given)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Stats</title></head><body>';
    echo '<p>Доступ запрещен</p>';
    echo '</body></html>';
    exit;
}

$events = readEvents();

$from = isset($_GET['from']) ? (string) $_GET['from'] : '';
$to = isset($_GET['to']) ? (string) $_GET['to'] : '';

$fromTs = $from !== '' ? strtotime($from . ' 00:00:00 UTC') : false;
$toTs = $to !== '' ? strtotime($to . ' 23:59:59 UTC') : false;

$filtered = [];
foreach ($events as $row) {
    $ts = $row['ts'] !== '' ? strtotime($row['ts']) : false;
    if ($fromTs !== false && ($ts === false || $ts < $fromTs)) {
        continue;
    }
    if ($toTs !== false && ($ts === false || $ts > $toTs)) {
        continue;
    }
    $filtered[] = $row;
}

/** @var array<string, array{raw: int, unique: array<string, true>}> $buckets */
$buckets = [];
foreach (VM_EVENTS as $name) {
    $buckets[$name] = ['raw' => 0, 'unique' => []];
}

foreach ($filtered as $row) {
    $name = $row['event'];
    if (!isset($buckets[$name])) {
        continue;
    }
    $buckets[$name]['raw']++;
    $vid = $row['vid'] ?? '';
    if ($vid !== '') {
        $buckets[$name]['unique'][$vid] = true;
    }
}

$visits = count($buckets['visit']['unique']);
$opens = count($buckets['quiz_open']['unique']);
$submits = count($buckets['quiz_submit']['unique']);

// Уникальные, у кого есть и visit, и quiz_open / submit — для честной конверсии по одному vid
$visitSet = $buckets['visit']['unique'];
$openSet = $buckets['quiz_open']['unique'];
$submitSet = $buckets['quiz_submit']['unique'];

$visitToOpen = 0;
foreach ($openSet as $vid => $_) {
    if (isset($visitSet[$vid])) {
        $visitToOpen++;
    }
}

$openToSubmit = 0;
foreach ($submitSet as $vid => $_) {
    if (isset($openSet[$vid])) {
        $openToSubmit++;
    }
}

$visitToSubmit = 0;
foreach ($submitSet as $vid => $_) {
    if (isset($visitSet[$vid])) {
        $visitToSubmit++;
    }
}

function pct(int $part, int $whole): string
{
    if ($whole <= 0) {
        return '—';
    }
    return number_format(($part / $whole) * 100, 1, '.', '') . '%';
}

// A/B test metrics: unique visitors, CTA clickers and successful leads per assigned variant.
$abStats = [
    'A' => ['visitors' => [], 'cta' => [], 'conversions' => []],
    'B' => ['visitors' => [], 'cta' => [], 'conversions' => []],
];
foreach ($filtered as $row) {
    $variant = $row['variant'] ?? '';
    $vid = (string) ($row['vid'] ?? '');
    if (!isset($abStats[$variant]) || $vid === '') {
        continue;
    }
    if ($row['event'] === 'landing_view') {
        $abStats[$variant]['visitors'][$vid] = true;
    } elseif ($row['event'] === 'cta_click') {
        $abStats[$variant]['cta'][$vid] = true;
    } elseif ($row['event'] === 'lead_submit') {
        $abStats[$variant]['conversions'][$vid] = true;
    }
}

$abSummary = [];
foreach (['A', 'B'] as $variant) {
    $abSummary[$variant] = [
        'visitors' => count($abStats[$variant]['visitors']),
        'cta' => count($abStats[$variant]['cta']),
        'conversions' => count($abStats[$variant]['conversions']),
    ];
    $abSummary[$variant]['rate'] = pct($abSummary[$variant]['conversions'], $abSummary[$variant]['visitors']);
}

$recent = array_slice(array_reverse($filtered), 0, 40);
$keyQ = htmlspecialchars($given, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fromEsc = htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$toEsc = htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$totalRows = count($filtered);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>ViMotion — воронка</title>
  <link rel="icon" href="../favicon.ico" sizes="any" />
  <link rel="icon" href="../favicon.svg" type="image/svg+xml" />
  <link rel="icon" href="../favicon.png" type="image/png" sizes="180x180" />
  <link rel="apple-touch-icon" href="../favicon.png" />
  <style>
    :root {
      --cream: #efe6de;
      --cola: #9a0002;
      --ink: #111111;
      --mute: #6a5f57;
      --line: rgba(17, 17, 17, 0.12);
      --card: rgba(255, 255, 255, 0.55);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100dvh;
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
      color: var(--ink);
      background: var(--cream);
      padding: 28px 20px 48px;
    }
    .wrap { max-width: 720px; margin: 0 auto; }
    h1 {
      font-size: clamp(1.4rem, 3vw, 1.85rem);
      letter-spacing: -0.02em;
      margin-bottom: 6px;
    }
    .sub { color: var(--mute); font-size: 0.95rem; margin-bottom: 22px; }
    form.filters {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: end;
      margin-bottom: 28px;
    }
    label { display: grid; gap: 4px; font-size: 0.8rem; color: var(--mute); }
    input[type="date"] {
      border: 1px solid var(--line);
      background: #fff;
      padding: 8px 10px;
      border-radius: 8px;
      font: inherit;
      color: var(--ink);
    }
    button, .link-btn {
      border: 0;
      background: var(--cola);
      color: #fff;
      padding: 9px 14px;
      border-radius: 8px;
      font: inherit;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
    }
    .link-btn { background: transparent; color: var(--mute); border: 1px solid var(--line); }
    .funnel {
      display: grid;
      gap: 12px;
      margin-bottom: 28px;
    }
    .step {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px 18px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 8px 16px;
      align-items: center;
    }
    .step h2 { font-size: 1.05rem; font-weight: 600; }
    .step .meta { color: var(--mute); font-size: 0.85rem; grid-column: 1 / -1; }
    .step .n { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.03em; }
    .conv {
      display: grid;
      gap: 10px;
      margin-bottom: 32px;
    }
    .conv-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      border-bottom: 1px solid var(--line);
      font-size: 0.95rem;
    }
    .conv-row strong { font-variant-numeric: tabular-nums; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }
    th, td {
      text-align: left;
      padding: 8px 6px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
    }
    th { color: var(--mute); font-weight: 500; }
    code { font-size: 0.8em; }
    h3 { font-size: 1rem; margin: 28px 0 10px; }
    .table-wrap { width: 100%; overflow-x: auto; }
    .ab-table { min-width: 540px; }
    .ab-variant { font-weight: 800; }
    .ab-variant--a { color: var(--wine, #8f0008); }
    .ab-variant--b { color: #1f6c42; }
    .note { margin: 10px 0 0; color: var(--mute); font-size: .82rem; line-height: 1.6; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Воронка ViMotion</h1>
    <p class="sub">Уникальные посетители по <code>vid</code> · событий в выборке: <?= (int) $totalRows ?></p>

    <form class="filters" method="get" action="">
      <input type="hidden" name="key" value="<?= $keyQ ?>" />
      <label>С
        <input type="date" name="from" value="<?= $fromEsc ?>" />
      </label>
      <label>По
        <input type="date" name="to" value="<?= $toEsc ?>" />
      </label>
      <button type="submit">Показать</button>
      <a class="link-btn" href="?key=<?= $keyQ ?>">Сбросить</a>
    </form>

    <h3>A/B-тест лендинга</h3>
    <p class="note">Конверсия = уникальные посетители, отправившие заявку, / уникальные посетители варианта. Заявка учитывается только после успешной отправки.</p>
    <div class="table-wrap">
      <table class="ab-table">
        <thead>
          <tr>
            <th>Вариант</th>
            <th>Посетители</th>
            <th>Нажали CTA</th>
            <th>Заявки</th>
            <th>Конверсия</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="ab-variant ab-variant--a">A · текущий</td>
            <td><?= (int) $abSummary['A']['visitors'] ?></td>
            <td><?= (int) $abSummary['A']['cta'] ?></td>
            <td><?= (int) $abSummary['A']['conversions'] ?></td>
            <td><strong><?= htmlspecialchars($abSummary['A']['rate'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td>
          </tr>
          <tr>
            <td class="ab-variant ab-variant--b">B · новая структура</td>
            <td><?= (int) $abSummary['B']['visitors'] ?></td>
            <td><?= (int) $abSummary['B']['cta'] ?></td>
            <td><?= (int) $abSummary['B']['conversions'] ?></td>
            <td><strong><?= htmlspecialchars($abSummary['B']['rate'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>

    <h3>Общая воронка сайта</h3>
    <div class="funnel">
      <div class="step">
        <h2>1. Посетители лендинга</h2>
        <div class="n"><?= (int) $visits ?></div>
        <p class="meta">событие <code>visit</code> · сырых: <?= (int) $buckets['visit']['raw'] ?></p>
      </div>
      <div class="step">
        <h2>2. Открыли анкету</h2>
        <div class="n"><?= (int) $opens ?></div>
        <p class="meta">событие <code>quiz_open</code> · сырых: <?= (int) $buckets['quiz_open']['raw'] ?></p>
      </div>
      <div class="step">
        <h2>3. Отправили анкету</h2>
        <div class="n"><?= (int) $submits ?></div>
        <p class="meta">событие <code>quiz_submit</code> · сырых: <?= (int) $buckets['quiz_submit']['raw'] ?></p>
      </div>
    </div>

    <h3>Конверсии (по пересечению vid)</h3>
    <div class="conv">
      <div class="conv-row">
        <span>Лендинг → анкета</span>
        <strong><?= pct($visitToOpen, $visits) ?> <span style="color:var(--mute);font-weight:500">(<?= (int) $visitToOpen ?>/<?= (int) $visits ?>)</span></strong>
      </div>
      <div class="conv-row">
        <span>Анкета → отправка</span>
        <strong><?= pct($openToSubmit, $opens) ?> <span style="color:var(--mute);font-weight:500">(<?= (int) $openToSubmit ?>/<?= (int) $opens ?>)</span></strong>
      </div>
      <div class="conv-row">
        <span>Лендинг → отправка</span>
        <strong><?= pct($visitToSubmit, $visits) ?> <span style="color:var(--mute);font-weight:500">(<?= (int) $visitToSubmit ?>/<?= (int) $visits ?>)</span></strong>
      </div>
    </div>

    <h3>Последние события</h3>
    <?php if ($recent === []): ?>
      <p class="sub">Пока пусто — открой лендинг и анкету после деплоя.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Время (UTC)</th>
            <th>Событие</th>
            <th>Вариант</th>
            <th>vid</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['ts'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><code><?= htmlspecialchars($row['event'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
              <td><?= htmlspecialchars((string) ($row['variant'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><code><?= htmlspecialchars((string) ($row['vid'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
