<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveRotationBacktester as Legacy;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research as H;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\OpportunityRotationBacktester as NewEngine;

require dirname(__DIR__) . '/bootstrap.php';
function opportunityAssert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
opportunityAssert(count(O::definitions()) === 74, '74 fixed configurations including controls.');
foreach (O::definitions() as $d) { O::validate(json_decode(json_encode($d['policy']), true)); }
$bars = $vvix = []; $universe = [];
foreach (array_merge(['SPY', 'QQQ'], array_map(static fn ($i) => 'S' . $i, range(0, 11))) as $k => $symbol) {
    if ($k >= 2) { $universe[] = $symbol; }
    $price = 100.0;
    for ($i = 0; $i < 550; $i++) {
        $date = new DateTimeImmutable('2020-01-01T21:00:00Z +' . $i . ' days');
        $open = $price * (1.0003 + 0.0002 * sin($i + $k));
        $price = $open * (1.002 + 0.006 * sin($i / 8.0 + $k));
        $bars[$symbol][] = new Bar($symbol, $date, $open, max($price, $open) * 1.01, min($price, $open) * 0.99, $price, 1e7 + $i * 1000);
        $vvix[$date->format('Y-m-d')] = 90.0;
    }
}
$features = O::features($bars, $vvix, $universe); $cut = '2021-03-01';
$truncated = O::features(H::truncateBars($bars, $cut), array_filter($vvix, static fn ($d) => $d <= $cut, ARRAY_FILTER_USE_KEY), $universe);
foreach ($truncated as $s => $rows) { opportunityAssert($rows === array_filter($features[$s], static fn ($d) => $d <= $cut, ARRAY_FILTER_USE_KEY), 'All factors and fitted predictions must be prefix-identical: ' . $s); }
$fitted = 0;
foreach ($features['S0'] as $r) { $fitted += (int) isset($r['conditional_504_3']); }
opportunityAssert($fitted > 0, 'Matured-outcome fixture must actually fit, not silently stay neutral.');
$p = ['family' => 'conditional_return', 'window' => 504, 'horizon' => 3, 'threshold' => 0.0, 'strength' => 0.5];
opportunityAssert(O::scale([], $p) === 1.0 && O::scale(['conditional_504_3' => -0.1], $p) === 0.5, 'Missing training neutral; negative conditional edge halves size.');
$p = ['family' => 'cost_band', 'window' => 20, 'strength' => 1.0];
$scores = ['A' => [], 'B' => []]; $f = ['A' => ['d' => ['drift' => 0.002]], 'B' => ['d' => ['drift' => 0.001]]];
opportunityAssert(O::choose('A', 'B', $scores, $f, 'd', $p, 30) === 'B', 'Marginal switch rejected after cost.');
opportunityAssert(O::choose('A', 'B', ['A' => []], $f, 'd', $p, 30) === 'A', 'Ineligible incumbent cannot be retained by no-trade band.');
$f['A']['d']['drift'] = 0.01;
opportunityAssert(O::choose('A', 'B', $scores, $f, 'd', $p, 30) === 'A', 'Sufficient drift advantage permits switch.');
$config = ['benchmark' => 'SPY', 'universe' => $universe, 'market_context' => ['symbol' => 'QQQ', 'sma_period' => 20],
    'factor_weights' => [5 => 1], 'volatility_period' => 5, 'volatility_score_power' => 0.0, 'volatility_target' => 0.4, 'max_gross' => 1.0,
    'rebalance_sessions' => 3, 'benchmark_sma_period' => 20, 'dollar_volume_period' => 1, 'min_dollar_volume' => 0.0,
    'minimum_history_sessions' => 20, 'cost_bps' => 30.0];
$old = (new Legacy($config))->run($bars, '2020-10-01', $cut);
$new = (new NewEngine($config))->run($bars, '2020-10-01', $cut);
opportunityAssert($old['curve'] === $new['curve'] && $old['next_target'] === $new['next_target'], 'Disabled derivative preserves exact legacy curve and preview.');
foreach (O::FACTORS as $family) {
    $p = ['family' => $family, 'window' => 20, 'strength' => 0.5];
    $c = $config + ['opportunity_policy' => $p, 'opportunity_features' => $features];
    $r = (new NewEngine($c))->run($bars, '2020-10-01', $cut);
    $c['opportunity_features'] = $truncated;
    $prefix = (new NewEngine($c))->run(H::truncateBars($bars, $cut), '2020-10-01', $cut);
    opportunityAssert($prefix === $r, 'Feature replay and target remain causal: ' . $family);
}
$mutated = $bars; $symbol = 'S0';
foreach ($mutated[$symbol] as $i => $b) {
    if (D::session($b) > $cut) { $mutated[$symbol][$i] = new Bar($symbol, $b->time, $b->open * 5, $b->high * 5, $b->low * 5, $b->close * 5, $b->volume); }
}
$shockFeatures = O::features($mutated, $vvix, $universe);
foreach ($truncated as $s => $rows) { opportunityAssert($rows === array_filter($shockFeatures[$s], static fn ($d) => $d <= $cut, ARRAY_FILTER_USE_KEY), 'Future shock cannot affect past training/predictions.'); }
foreach ([['family' => 'trend_r2', 'window' => 20, 'strength' => NAN], ['family' => 'unknown', 'window' => 20, 'strength' => 0.5]] as $p) {
    $threw = false; try { O::validate($p); } catch (InvalidArgumentException) { $threw = true; }
    opportunityAssert($threw, 'Malformed policy rejected.');
}
echo "Opportunity factors, matured labels, future-mutation tests, policy validation and exact legacy replay OK\n";
