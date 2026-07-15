#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Support\TelegramMessageClassifier;

require __DIR__ . '/../bootstrap.php';

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

$classifier = new TelegramMessageClassifier();
$rows = [];
foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $text = (string) ($event['text'] ?? '');
    $classification = $classifier->classify($event);
    $actions = [];
    foreach (($classification['types'] ?? []) as $type) {
        $action = match ($type) {
            'entry', 'add', 'hold', 'exit' => $type,
            'stop_to_breakeven' => 'stop',
            default => null,
        };
        if ($action !== null) {
            $actions[$action] = true;
        }
    }
    if ($actions === []) {
        continue;
    }

    $verified = ($classification['verified_real_action'] ?? false) === true;
    $confidence = $verified ? 0.95 : 0.35;
    $confidence += (!$verified && (string) ($event['author'] ?? '') === 'FTT_Admin Official') ? 0.10 : 0.0;
    $confidence += (!$verified && ($event['tickers'] ?? []) !== []) ? 0.10 : 0.0;

    $rows[] = [
        'date' => $event['date'] ?? null,
        'date_raw' => $event['date_raw'] ?? null,
        'message_id' => $event['message_id'] ?? null,
        'author' => $event['author'] ?? null,
        'tickers' => is_array($event['tickers'] ?? null) ? $event['tickers'] : [],
        'actions' => array_keys($actions),
        'confidence' => min(1.0, $confidence),
        'verified_real_action' => $verified,
        'action_verification_reason' => $classification['action_verification_reason'] ?? 'verification_unavailable',
        'primary_type' => $classification['primary_type'] ?? 'other',
        'support_mentions' => is_array($event['support_mentions'] ?? null) ? $event['support_mentions'] : [],
        'text_excerpt' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 800),
    ];
}

$summary = [
    'by_action' => [],
    'by_verified_action' => [],
    'by_ticker' => [],
    'verified_real_actions' => 0,
    'unverified_action_mentions' => 0,
];
foreach ($rows as $row) {
    if ($row['verified_real_action']) {
        $summary['verified_real_actions']++;
    } else {
        $summary['unverified_action_mentions']++;
    }
    foreach ($row['actions'] as $action) {
        $summary['by_action'][$action] = ($summary['by_action'][$action] ?? 0) + 1;
        if ($row['verified_real_action']) {
            $summary['by_verified_action'][$action] = ($summary['by_verified_action'][$action] ?? 0) + 1;
        }
    }
    foreach ($row['tickers'] as $ticker) {
        $summary['by_ticker'][$ticker] = ($summary['by_ticker'][$ticker] ?? 0) + 1;
    }
}
arsort($summary['by_action']);
arsort($summary['by_verified_action']);
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
