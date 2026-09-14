<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveRotationBacktester;
use FulltimeTrading\Research\AdaptiveRotationEnsembleBacktester;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\PortfolioCircuitController;

require __DIR__ . '/adaptive_rotation_research.php';
function stopAssert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$stopConfig = array_replace($config, ['universe' => ['AAA'], 'standing_stop_pct' => 0.05, 'position_exit_cooldown_sessions' => 3, 'drawdown_kill_pct' => 0.95]);
$tester = new AdaptiveRotationBacktester($stopConfig);
$r = $tester->run($bars, '2024-01-26', '2024-03-25', 1000.0);
stopAssert(count($r['standing_stop_events']) > 0, 'Fixture must exercise a real stop.');
$event = $r['standing_stop_events'][0];
stopAssert($event['kind'] === 'stop_level' && abs($event['fill'] - $event['stop']) < 1e-12, 'Standing stop is fixed before the adverse price move.');
$rows = array_column($r['curve'], null, 'date');
stopAssert($rows[$event['date']]['holding'] === null && $rows[$event['date']]['gross_close'] === 0.0, 'Stopped position must be cash at close.');
$priceBar = array_values(array_filter($bars['AAA'], static fn ($b): bool => $b->time->format('Y-m-d') === $event['date']))[0];
stopAssert($event['stop'] > $priceBar->low, 'Daily low must not be mistaken for the normal stop execution.');
$worst = (new AdaptiveRotationBacktester(array_replace($stopConfig, ['standing_stop_fill' => 'daily_low'])))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
stopAssert($worst['standing_stop_events'][0]['fill'] === $priceBar->low, 'Separate deliberately adverse full-bar-low stress.');
$gapBars = $bars;
$index = array_search($priceBar, $bars['AAA'], true);
$open = $bars['AAA'][$index - 1]->close * 0.60;
$gapBars['AAA'][$index] = new Bar('AAA', $priceBar->time, $open, $open * 1.01, $open * 0.9, $open * 0.95, $priceBar->volume);
$gap = $tester->run($gapBars, '2024-01-26', '2024-03-25', 1000.0);
$fill = $gap['standing_stop_events'][0];
stopAssert($fill['kind'] === 'gap_open' && $fill['fill'] === $open && $fill['fill'] < $fill['stop'], 'A gap must fill below the stop, never at an unavailable price.');
$previousPeak = $r['curve'][1]['equity'];
$gapRows = array_column($gap['curve'], null, 'date');
stopAssert($gapRows[$event['date']]['equity'] < $previousPeak, 'Gap loss cannot disappear from account equity.');
$costly = (new AdaptiveRotationBacktester(array_replace($stopConfig, ['cost_bps' => 30])))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
stopAssert($costly['curve'][7]['equity'] < $r['curve'][7]['equity'], 'Stop sells pay transaction costs.');
$end = $event['date'];
$prefix = $tester->run(HybridV4Research::truncateBars($bars, $end), '2024-01-26', $end, 1000.0);
stopAssert($prefix['curve'] === array_values(array_filter($r['curve'], static fn ($row): bool => $row['date'] <= $end)), 'Standing-stop replay is causal.');
$ensemble = new AdaptiveRotationEnsembleBacktester(['one' => ['allocation' => 0.4, 'config' => $config], 'two' => ['allocation' => 0.6, 'config' => $config]]);
$ordinary = $ensemble->run($bars, '2024-01-26', '2024-03-25', 1000.0);
$neutral = $ensemble->runControlled($bars, '2024-01-26', '2024-03-25', new PortfolioCircuitController(), 1000.0);
stopAssert($ordinary === $neutral, 'Synchronized neutral controller must reproduce the entire independent ensemble exactly.');
$dates = array_column($ordinary['curve'], 'date');
$confirmation = array_fill_keys($dates, false);
$guard = new PortfolioCircuitController(['drawdown' => 0.10, 'release' => 'confirmed', 'minimum_pause' => 2], $confirmation);
$controlled = $ensemble->runControlled($bars, '2024-01-26', '2024-03-25', $guard, 1000.0);
$events = $guard->report()['events'];
stopAssert(count($events) === 1 && $events[0]['event'] === 'liquidate_next_open', 'No confirmation means no automatic expiry reentry.');
$trigger = $events[0]['date'];
foreach ($controlled['curve'] as $row) {
    if ($row['date'] > $trigger) { stopAssert($row['holding'] === null, 'All sleeves remain cash while shared circuit is closed.'); }
}
$guard = new PortfolioCircuitController(['drawdown' => 0.10, 'pause' => 2]);
$fixed = $ensemble->runControlled($bars, '2024-01-26', '2024-03-25', $guard, 1000.0);
$events = $guard->report()['events'];
stopAssert($events[1]['elapsed_cash_sessions'] === 2, 'Fixed pause counts two completed cash sessions after trigger.');
$cut = $events[1]['date'];
$prefix = $ensemble->runControlled(HybridV4Research::truncateBars($bars, $cut), '2024-01-26', $cut, new PortfolioCircuitController(['drawdown' => 0.10, 'pause' => 2]), 1000.0);
stopAssert($prefix['curve'] === array_values(array_filter($fixed['curve'], static fn ($row): bool => $row['date'] <= $cut)), 'Shared controller has no future feedback.');
stopAssert($fixed['curve'][0]['start_equity'] === 1000.0, 'Risk epoch reset never resets measured initial capital.');
$rejected = false;
try { $ensemble->runControlled($bars, '2024-01-26', '2024-03-25', new PortfolioCircuitController(['release' => 'confirmed']), 1000.0); }
catch (RuntimeException) { $rejected = true; }
stopAssert($rejected, 'Missing market confirmation fails closed.');
echo "Portfolio/standing-stop research tests OK\n";
