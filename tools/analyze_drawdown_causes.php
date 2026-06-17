#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = [
    'equity' => __DIR__ . '/../var/reports/author_grid/best_equity.json',
    'trades' => __DIR__ . '/../var/reports/author_grid/best_trades.json',
    'output' => __DIR__ . '/../var/reports/author_grid/drawdown_causes.json',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$equityPayload = json_decode((string) file_get_contents((string) $options['equity']), true, 512, JSON_THROW_ON_ERROR);
$tradesPayload = json_decode((string) file_get_contents((string) $options['trades']), true, 512, JSON_THROW_ON_ERROR);
$equity = $equityPayload['equity'] ?? [];
$trades = $tradesPayload['trades'] ?? [];
if (!is_array($equity) || !is_array($trades)) {
    throw new RuntimeException('Invalid input JSON.');
}

$drawdown = maxDrawdownWindow($equity);
$lossByReason = [];
$lossBySymbol = [];
$worstTrades = [];
$periodTrades = [];

foreach ($trades as $trade) {
    if (!is_array($trade)) {
        continue;
    }
    $pnl = (float) ($trade['pnl'] ?? 0.0);
    if ($pnl < 0.0) {
        $reason = (string) ($trade['exit_reason'] ?? 'unknown');
        $symbol = (string) ($trade['symbol'] ?? 'unknown');
        $lossByReason[$reason] = ($lossByReason[$reason] ?? 0.0) + $pnl;
        $lossBySymbol[$symbol] = ($lossBySymbol[$symbol] ?? 0.0) + $pnl;
        $worstTrades[] = $trade;
    }
    if (
        $drawdown['peak_date'] !== null
        && $drawdown['trough_date'] !== null
        && (string) ($trade['exit_date'] ?? '') >= $drawdown['peak_date']
        && (string) ($trade['exit_date'] ?? '') <= $drawdown['trough_date']
    ) {
        $periodTrades[] = $trade;
    }
}

asort($lossByReason);
asort($lossBySymbol);
usort($worstTrades, static fn (array $a, array $b): int => (float) $a['pnl'] <=> (float) $b['pnl']);

$periodByReason = [];
$periodBySymbol = [];
foreach ($periodTrades as $trade) {
    $reason = (string) ($trade['exit_reason'] ?? 'unknown');
    $symbol = (string) ($trade['symbol'] ?? 'unknown');
    $pnl = (float) ($trade['pnl'] ?? 0.0);
    $periodByReason[$reason] = ($periodByReason[$reason] ?? 0.0) + $pnl;
    $periodBySymbol[$symbol] = ($periodBySymbol[$symbol] ?? 0.0) + $pnl;
}
asort($periodByReason);
asort($periodBySymbol);
$periodLosses = array_values(array_filter($periodTrades, static fn (array $trade): bool => (float) ($trade['pnl'] ?? 0.0) < 0.0));
$periodWins = array_values(array_filter($periodTrades, static fn (array $trade): bool => (float) ($trade['pnl'] ?? 0.0) > 0.0));
usort($periodTrades, static fn (array $a, array $b): int => (float) $a['pnl'] <=> (float) $b['pnl']);

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'equity' => $options['equity'],
    'trades' => $options['trades'],
    'variant' => $equityPayload['variant'] ?? $tradesPayload['variant'] ?? null,
    'max_drawdown_window' => $drawdown,
    'all_losses' => [
        'by_reason' => $lossByReason,
        'by_symbol' => array_slice($lossBySymbol, 0, 20, true),
        'worst_trades' => array_slice($worstTrades, 0, 20),
    ],
    'max_drawdown_period' => [
        'trades' => count($periodTrades),
        'wins' => count($periodWins),
        'losses' => count($periodLosses),
        'pnl' => array_sum(array_map(static fn (array $trade): float => (float) ($trade['pnl'] ?? 0.0), $periodTrades)),
        'by_reason' => $periodByReason,
        'by_symbol' => array_slice($periodBySymbol, 0, 20, true),
        'worst_trades' => array_slice($periodTrades, 0, 20),
    ],
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output directory: ' . $dir);
}
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode([
    'variant' => $result['variant'],
    'max_drawdown_window' => $result['max_drawdown_window'],
    'loss_by_reason' => $result['all_losses']['by_reason'],
    'loss_by_symbol' => $result['all_losses']['by_symbol'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "Report: {$output}\n";

/** @param list<array<string, mixed>> $equity */
function maxDrawdownWindow(array $equity): array
{
    $peak = null;
    $peakDate = null;
    $maxDrawdown = 0.0;
    $drawdownPeak = null;
    $drawdownPeakEquity = null;
    $drawdownTrough = null;
    $drawdownTroughEquity = null;

    foreach ($equity as $point) {
        $value = (float) ($point['equity'] ?? 0.0);
        $date = (string) ($point['date'] ?? '');
        if ($date === '') {
            continue;
        }
        if ($peak === null || $value > $peak) {
            $peak = $value;
            $peakDate = $date;
        }
        if ($peak !== null && $peak > 0.0) {
            $drawdown = ($value - $peak) / $peak;
            if ($drawdown < $maxDrawdown) {
                $maxDrawdown = $drawdown;
                $drawdownPeak = $peakDate;
                $drawdownPeakEquity = $peak;
                $drawdownTrough = $date;
                $drawdownTroughEquity = $value;
            }
        }
    }

    return [
        'drawdown_pct' => $maxDrawdown,
        'peak_date' => $drawdownPeak,
        'peak_equity' => $drawdownPeakEquity,
        'trough_date' => $drawdownTrough,
        'trough_equity' => $drawdownTroughEquity,
        'loss' => $drawdownTroughEquity !== null && $drawdownPeakEquity !== null
            ? $drawdownTroughEquity - $drawdownPeakEquity
            : null,
    ];
}
