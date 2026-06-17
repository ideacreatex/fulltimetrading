#!/usr/bin/env php
<?php

declare(strict_types=1);

$input = __DIR__ . '/../var/reports/telegram_setups.json';
$output = __DIR__ . '/../var/reports/telegram_trade_actions.json';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $input = substr($arg, 8);
    } elseif (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

if (!is_file($input)) {
    throw new RuntimeException('Input not found: ' . $input);
}

$payload = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
$events = $payload['events'] ?? [];
if (!is_array($events)) {
    throw new RuntimeException('Input has no events array.');
}

$actionPatterns = [
    'entry' => [
        '/\bвзял[аи]?\b/iu',
        '/\bкупил[аи]?\b/iu',
        '/\bоткрыл[аи]?\b/iu',
        '/\bзаш[её]л\b/iu',
        '/\bначина(?:ю|ем)\s+открывать\b/iu',
    ],
    'add' => [
        '/\bдобав(?:ил|ила|ляем|ляю|ить)\b/iu',
        '/\bнагруз(?:ил|ила|ить|ку|ка|аем|аю)\b/iu',
        '/\bзаполн(?:ил|яем|яю|ить)\b/iu',
    ],
    'hold' => [
        '/\bдержу\b/iu',
        '/\bдержим\b/iu',
        '/\bне\s+нарушена\b/iu',
        '/\bстратегия\s+не\s+нарушена\b/iu',
    ],
    'exit' => [
        '/\bзакрыл[аи]?\b/iu',
        '/\bзакрываем\b/iu',
        '/\bфикс(?:ирую|ируем|ировал|ировать)\b/iu',
        '/\bв\s+к[эе]ш\b/iu',
    ],
    'stop' => [
        '/\bстоп(?:нул|имся|иться|ы|ом)?\b/iu',
        '/\bбу\b/iu',
        '/\bбезубыт/i',
        '/\bжестк(?:ий|ого)?\s+стоп\b/iu',
    ],
    'plan' => [
        '/\bпланир(?:ую|уем)\b/iu',
        '/\bжд[её]м\b/iu',
        '/\bточк[аи]\s+входа\b/iu',
        '/\bможно\s+ожидать\b/iu',
    ],
];

$rows = [];
foreach ($events as $event) {
    $text = (string) ($event['text'] ?? '');
    $actions = [];
    foreach ($actionPatterns as $action => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $actions[$action] = true;
                break;
            }
        }
    }
    if ($actions === []) {
        continue;
    }

    $confidence = 0.35;
    if (isset($actions['entry']) || isset($actions['add']) || isset($actions['exit'])) {
        $confidence += 0.35;
    }
    if (isset($actions['hold']) || isset($actions['stop'])) {
        $confidence += 0.15;
    }
    if ((string) ($event['author'] ?? '') === 'FTT_Admin Official') {
        $confidence += 0.15;
    }

    $rows[] = [
        'date' => $event['date'],
        'date_raw' => $event['date_raw'],
        'message_id' => $event['message_id'],
        'author' => $event['author'],
        'tickers' => $event['tickers'],
        'actions' => array_keys($actions),
        'confidence' => min(1.0, $confidence),
        'support_mentions' => $event['support_mentions'],
        'text_excerpt' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 800),
    ];
}

$summary = [];
foreach ($rows as $row) {
    foreach ($row['actions'] as $action) {
        $summary['by_action'][$action] = ($summary['by_action'][$action] ?? 0) + 1;
    }
    foreach ($row['tickers'] as $ticker) {
        $summary['by_ticker'][$ticker] = ($summary['by_ticker'][$ticker] ?? 0) + 1;
    }
}
arsort($summary['by_ticker']);

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'input' => $input,
    'action_count' => count($rows),
    'summary' => $summary,
    'actions' => $rows,
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
}

$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output directory: ' . $dir);
}
file_put_contents($output, $json . "\n");

echo 'Trade/action messages extracted: ' . count($rows) . "\n";
echo "Report: {$output}\n";
