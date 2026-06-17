#!/usr/bin/env php
<?php

declare(strict_types=1);

$input = __DIR__ . '/../var/reports/telegram_setup_analysis.json';
$output = __DIR__ . '/../var/reports/regularity_universe.json';
$symbolsOutput = __DIR__ . '/../var/reports/regularity_symbols.txt';
$minEvents = 5;
$minClearRate = 0.55;
$minWorkedRate = 0.65;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $input = substr($arg, 8);
    } elseif (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    } elseif (str_starts_with($arg, '--symbols-output=')) {
        $symbolsOutput = substr($arg, 17);
    } elseif (str_starts_with($arg, '--min-events=')) {
        $minEvents = (int) substr($arg, 13);
    } elseif (str_starts_with($arg, '--min-clear-rate=')) {
        $minClearRate = (float) substr($arg, 17);
    } elseif (str_starts_with($arg, '--min-worked-rate=')) {
        $minWorkedRate = (float) substr($arg, 18);
    }
}

if (!is_file($input)) {
    throw new RuntimeException('Input not found: ' . $input);
}

$payload = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['rows'] ?? [];
if (!is_array($rows)) {
    throw new RuntimeException('Input has no rows array.');
}

$exclude = [
    'SPY', 'SPX', 'ES', 'ES1', 'QQQ', 'NQ', 'NQ1', 'NDX', 'IXIC', 'SMH', 'RSP', 'IWM', 'DIA', 'YM', 'YM1',
    'VIX', 'VVIX', 'DXY', 'US20Y', 'NYA', 'EDOW', 'SX5E', 'SXXP', 'MAGS', 'M2SL', 'PCC', 'PCSP',
    'UPRO', 'SPXL', 'SPUU', 'SSO', 'TQQQ', 'QLD', 'SOXL', 'UDOW', 'SVXY', 'SVIX', 'SVYX',
];

$stats = [];
foreach ($rows as $row) {
    $ticker = (string) $row['ticker'];
    if (in_array($ticker, $exclude, true)) {
        continue;
    }

    $stats[$ticker]['symbol'] = $ticker;
    $stats[$ticker]['events'] = ($stats[$ticker]['events'] ?? 0) + 1;
    $stats[$ticker]['clear'] = ($stats[$ticker]['clear'] ?? 0) + ($row['verdict'] === 'clear_prior_regularity' ? 1 : 0);
    $stats[$ticker]['worked'] = ($stats[$ticker]['worked'] ?? 0) + (in_array($row['verdict'], ['clear_prior_regularity', 'worked_forward_without_prior_score'], true) ? 1 : 0);
    $stats[$ticker]['avg_20d_return_pct'] = ($stats[$ticker]['avg_20d_return_pct'] ?? 0.0) + (float) ($row['forward']['return_20d_pct'] ?? 0.0);
    $stats[$ticker]['avg_63d_return_pct'] = ($stats[$ticker]['avg_63d_return_pct'] ?? 0.0) + (float) ($row['forward']['return_63d_pct'] ?? 0.0);
}

$selected = [];
foreach ($stats as $ticker => $stat) {
    $events = (int) $stat['events'];
    $stat['clear_rate'] = $events > 0 ? $stat['clear'] / $events : 0.0;
    $stat['worked_rate'] = $events > 0 ? $stat['worked'] / $events : 0.0;
    $stat['avg_20d_return_pct'] = $events > 0 ? $stat['avg_20d_return_pct'] / $events : 0.0;
    $stat['avg_63d_return_pct'] = $events > 0 ? $stat['avg_63d_return_pct'] / $events : 0.0;

    if ($events >= $minEvents && $stat['clear_rate'] >= $minClearRate && $stat['worked_rate'] >= $minWorkedRate) {
        $selected[$ticker] = $stat;
    }
}

uasort($selected, static function (array $a, array $b): int {
    return $b['clear_rate'] <=> $a['clear_rate']
        ?: $b['worked_rate'] <=> $a['worked_rate']
        ?: $b['events'] <=> $a['events'];
});

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'input' => $input,
    'criteria' => [
        'min_events' => $minEvents,
        'min_clear_rate' => $minClearRate,
        'min_worked_rate' => $minWorkedRate,
    ],
    'symbols' => array_keys($selected),
    'items' => array_values($selected),
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
}

foreach ([$output, $symbolsOutput] as $file) {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create output directory: ' . $dir);
    }
}
file_put_contents($output, $json . "\n");
file_put_contents($symbolsOutput, implode("\n", array_keys($selected)) . "\n");

echo 'Regularity symbols: ' . count($selected) . "\n";
echo "Report: {$output}\n";
echo "Symbols: {$symbolsOutput}\n";
