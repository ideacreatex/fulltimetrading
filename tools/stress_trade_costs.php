#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$options = [
    'trades' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_trades.json',
    'equity' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_equity.json',
    'slippage-bps' => '0,5,10,20,50',
    'commission-per-order' => '0',
    'output' => '',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$tradePayload = json_decode((string) file_get_contents((string) $options['trades']), true);
$equityPayload = json_decode((string) file_get_contents((string) $options['equity']), true);
if (!is_array($tradePayload) || !is_array($equityPayload)) {
    throw new RuntimeException('Invalid input JSON.');
}

$trades = $tradePayload['trades'] ?? [];
$equity = $equityPayload['equity'] ?? [];
if (!is_array($trades) || !is_array($equity)) {
    throw new RuntimeException('Input files must contain trades/equity arrays.');
}

$scenarios = array_values(array_filter(array_map(
    static fn (string $value): float => (float) trim($value),
    explode(',', (string) $options['slippage-bps']),
), static fn (float $value): bool => $value >= 0.0));
$commissionPerOrder = (float) $options['commission-per-order'];

$rows = [];
foreach ($scenarios as $slippageBps) {
    $rows[] = stressScenario($trades, $equity, $slippageBps, $commissionPerOrder);
}

$result = [
    'trades' => (string) $options['trades'],
    'equity' => (string) $options['equity'],
    'commission_per_order' => $commissionPerOrder,
    'rows' => $rows,
];

if ((string) $options['output'] !== '') {
    file_put_contents((string) $options['output'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

echo markdown($result);

/**
 * @param list<array<string, mixed>> $trades
 * @param list<array{date:string, equity:float}> $equity
 * @return array<string, mixed>
 */
function stressScenario(array $trades, array $equity, float $slippageBps, float $commissionPerOrder): array
{
    $costsByDate = [];
    $totalCost = 0.0;
    $adjustedTradePnl = 0.0;
    $grossPnl = 0.0;
    $slippage = $slippageBps / 10000.0;

    foreach ($trades as $trade) {
        if (!is_array($trade)) {
            continue;
        }
        $entry = (float) ($trade['entry'] ?? 0.0);
        $exit = (float) ($trade['exit'] ?? 0.0);
        $shares = (float) ($trade['shares'] ?? 0.0);
        $pnl = (float) ($trade['pnl'] ?? 0.0);
        $date = (string) ($trade['exit_date'] ?? '');
        if ($entry <= 0.0 || $exit <= 0.0 || $shares <= 0.0 || $date === '') {
            continue;
        }

        $notional = ($entry + $exit) * $shares;
        $cost = $notional * $slippage + $commissionPerOrder * 2.0;
        $costsByDate[$date] = ($costsByDate[$date] ?? 0.0) + $cost;
        $totalCost += $cost;
        $grossPnl += $pnl;
        $adjustedTradePnl += $pnl - $cost;
    }

    $adjustedCurve = [];
    $cumulativeCost = 0.0;
    foreach ($equity as $point) {
        if (!is_array($point)) {
            continue;
        }
        $date = (string) ($point['date'] ?? '');
        $cumulativeCost += $costsByDate[$date] ?? 0.0;
        $adjustedCurve[] = [
            'date' => $date,
            'equity' => max(0.01, (float) ($point['equity'] ?? 0.0) - $cumulativeCost),
        ];
    }

    return [
        'slippage_bps_per_side' => $slippageBps,
        'total_cost' => $totalCost,
        'gross_trade_pnl' => $grossPnl,
        'adjusted_trade_pnl' => $adjustedTradePnl,
        'return_pct' => returnPct($adjustedCurve),
        'annualized_return_pct' => annualizedReturnPct($adjustedCurve),
        'max_drawdown_pct' => maxDrawdownPct($adjustedCurve),
        'ending_equity' => $adjustedCurve === [] ? 0.0 : (float) $adjustedCurve[array_key_last($adjustedCurve)]['equity'],
    ];
}

/** @param list<array{date:string, equity:float}> $curve */
function returnPct(array $curve): float
{
    if ($curve === []) {
        return 0.0;
    }
    $first = (float) $curve[0]['equity'];
    $last = (float) $curve[array_key_last($curve)]['equity'];

    return $first > 0.0 ? ($last - $first) / $first : 0.0;
}

/** @param list<array{date:string, equity:float}> $curve */
function annualizedReturnPct(array $curve): float
{
    if (count($curve) < 2) {
        return 0.0;
    }

    $first = (float) $curve[0]['equity'];
    $last = (float) $curve[array_key_last($curve)]['equity'];
    $start = new DateTimeImmutable((string) $curve[0]['date']);
    $end = new DateTimeImmutable((string) $curve[array_key_last($curve)]['date']);
    $years = max(1.0 / 365.25, $start->diff($end)->days / 365.25);

    return $first > 0.0 && $last > 0.0 ? ($last / $first) ** (1.0 / $years) - 1.0 : 0.0;
}

/** @param list<array{date:string, equity:float}> $curve */
function maxDrawdownPct(array $curve): float
{
    $peak = null;
    $maxDrawdown = 0.0;
    foreach ($curve as $point) {
        $equity = (float) $point['equity'];
        if ($peak === null || $equity > $peak) {
            $peak = $equity;
            continue;
        }
        if ($peak > 0.0) {
            $maxDrawdown = min($maxDrawdown, ($equity - $peak) / $peak);
        }
    }

    return $maxDrawdown;
}

/** @param array<string, mixed> $result */
function markdown(array $result): string
{
    $lines = [];
    $lines[] = '# Trade cost stress';
    $lines[] = '';
    $lines[] = '| Slippage bps/side | Total cost | Ending equity | Total return | Annualized | Max DD | Adjusted trade PnL |';
    $lines[] = '|---:|---:|---:|---:|---:|---:|---:|';
    foreach ($result['rows'] as $row) {
        $lines[] = sprintf(
            '| %.1f | %.2f | %.2f | %+.2f%% | %+.2f%% | %+.2f%% | %+.2f |',
            (float) $row['slippage_bps_per_side'],
            (float) $row['total_cost'],
            (float) $row['ending_equity'],
            (float) $row['return_pct'] * 100,
            (float) $row['annualized_return_pct'] * 100,
            (float) $row['max_drawdown_pct'] * 100,
            (float) $row['adjusted_trade_pnl'],
        );
    }

    return implode("\n", $lines) . "\n";
}
