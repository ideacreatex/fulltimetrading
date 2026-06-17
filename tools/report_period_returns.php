#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$options = [
    'report' => __DIR__ . '/../var/reports/risk_grid/best_40_35_report.json',
    'period' => 'all',
    'format' => 'markdown',
    'output' => '',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$payload = json_decode((string) file_get_contents((string) $options['report']), true);
if (!is_array($payload)) {
    throw new RuntimeException('Invalid report JSON: ' . (string) $options['report']);
}

$report = $payload['report'] ?? $payload;
if (!is_array($report)) {
    throw new RuntimeException('Report payload is missing.');
}

$period = (string) $options['period'];
$sections = match ($period) {
    'years', 'year' => ['years' => $report['years'] ?? []],
    'quarters', 'quarter' => ['quarters' => $report['quarters'] ?? []],
    'all' => [
        'years' => $report['years'] ?? [],
        'quarters' => $report['quarters'] ?? [],
    ],
    default => throw new InvalidArgumentException('Unknown period: ' . $period),
};

$result = [
    'source' => (string) $options['report'],
    'variant' => $payload['variant'] ?? null,
    'params' => $payload['params'] ?? null,
    'summary' => $report['summary'] ?? null,
    'benchmark' => $report['benchmark'] ?? null,
    'sections' => $sections,
];

if ((string) $options['format'] === 'json') {
    $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    $output = markdownReport($result);
}

if ((string) $options['output'] !== '') {
    file_put_contents((string) $options['output'], $output);
    echo "Wrote " . (string) $options['output'] . "\n";
} else {
    echo $output;
}

/** @param array<string, mixed> $result */
function markdownReport(array $result): string
{
    $lines = [];
    $variant = (string) ($result['variant'] ?? 'unknown');
    $lines[] = '# Period returns: ' . $variant;
    $lines[] = '';

    if (is_array($result['summary'] ?? null)) {
        $summary = $result['summary'];
        $lines[] = sprintf(
            'Summary: total `%s`, annualized `%s`, max DD `%s`, trades `%d`, PF `%s`, Sharpe `%s`.',
            pct((float) ($summary['return_pct'] ?? 0.0)),
            pct((float) ($summary['annualized_return_pct'] ?? 0.0)),
            pct((float) ($summary['max_drawdown_pct'] ?? 0.0)),
            (int) ($summary['trades'] ?? 0),
            numberOrNull($summary['profit_factor'] ?? null),
            numberOrNull($summary['sharpe'] ?? null),
        );
        $lines[] = '';
    }

    /** @var array<string, array<string, array<string, mixed>>> $sections */
    $sections = $result['sections'];
    foreach ($sections as $name => $rows) {
        if (!is_array($rows) || $rows === []) {
            continue;
        }
        $lines[] = '## ' . ucfirst($name);
        $lines[] = '';
        $lines[] = '| Period | Strategy | SPY | Excess | Max DD | SPY DD | Trades | Win rate | PF | PnL |';
        $lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($rows as $period => $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %d | %s | %s | %s |',
                (string) $period,
                pct((float) ($row['strategy_return_pct'] ?? 0.0)),
                pct((float) ($row['spy_return_pct'] ?? 0.0)),
                pct((float) ($row['excess_return_pct'] ?? 0.0)),
                pct((float) ($row['strategy_max_drawdown_pct'] ?? 0.0)),
                pct((float) ($row['spy_max_drawdown_pct'] ?? 0.0)),
                (int) ($row['trades'] ?? 0),
                pct((float) ($row['win_rate'] ?? 0.0)),
                numberOrNull($row['profit_factor'] ?? null),
                money((float) ($row['pnl'] ?? 0.0)),
            );
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}

function pct(float $value): string
{
    return sprintf('%+.2f%%', $value * 100.0);
}

function money(float $value): string
{
    return sprintf('%+.2f', $value);
}

function numberOrNull(mixed $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return sprintf('%.2f', (float) $value);
}
