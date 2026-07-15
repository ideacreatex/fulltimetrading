<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/ftt-telegram-actions-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create Telegram action test directory.');
}

$input = $tempDir . '/input.json';
$output = $tempDir . '/output.json';
$events = [
    [
        'date' => '2026-06-15',
        'date_raw' => '2026-06-15T10:00:00+00:00',
        'message_id' => '1',
        'author' => 'FTT_Admin Official',
        'tickers' => [],
        'support_mentions' => [],
        'text' => 'Получили сигнал, открыли график, приняли решение.',
    ],
    [
        'date' => '2026-07-07',
        'date_raw' => '2026-07-07T10:00:00+00:00',
        'message_id' => '2',
        'author' => 'FTT_Admin Official',
        'tickers' => [],
        'support_mentions' => [],
        'text' => 'Дисциплина добавляет самоанализа каждой позиции.',
    ],
    [
        'date' => '2026-07-10',
        'date_raw' => '2026-07-10T10:00:00+00:00',
        'message_id' => '3',
        'author' => 'FTT_Admin Official',
        'tickers' => ['TQQQ'],
        'support_mentions' => [],
        'text' => 'Купил TQQQ по сигналу.',
    ],
    [
        'date' => '2026-07-10',
        'date_raw' => '2026-07-10T11:00:00+00:00',
        'message_id' => '4',
        'author' => 'FTT_Admin Official',
        'tickers' => [],
        'support_mentions' => [],
        'text' => 'Открыл позицию и заработал на ней.',
    ],
];
file_put_contents($input, json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

$command = [
    PHP_BINARY,
    $root . '/tools/extract_telegram_trades.php',
    '--input=' . $input,
    '--output=' . $output,
];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Telegram action extractor.');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

try {
    actionExpect($exitCode === 0, 'Extractor failed: ' . $stderr . ' ' . $stdout);
    $report = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    actionExpect(($report['action_count'] ?? null) === 2, 'Only two actual action mentions should remain.');
    actionExpect(($report['summary']['verified_real_actions'] ?? null) === 1, 'Exactly one ticker-bound action must be verified.');
    actionExpect(($report['summary']['unverified_action_mentions'] ?? null) === 1, 'The tickerless position mention must remain unverified.');
    $rows = is_array($report['actions'] ?? null) ? $report['actions'] : [];
    actionExpect(($rows[0]['message_id'] ?? null) === '3', 'Opening a graph and adding introspection must not create action rows.');
    actionExpect(($rows[0]['verified_real_action'] ?? false) === true, 'Ticker-bound executed buy must be verified.');
    actionExpect(($rows[1]['verified_real_action'] ?? true) === false, 'Tickerless entry mention must not be verified.');
} finally {
    @unlink($input);
    @unlink($output);
    @rmdir($tempDir);
}

echo "Telegram trade action extraction OK\n";

function actionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
