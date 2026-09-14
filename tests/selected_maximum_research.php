<?php

declare(strict_types=1);

use FulltimeTrading\Research\SelectedMaximumResearch as S;

require dirname(__DIR__) . '/bootstrap.php';
function selectedAssert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
selectedAssert(S::cap(['a' => 1.25, 'b' => 1.25], ['a' => 1, 'b' => 0.5]) === ['a' => 1.25, 'b' => 0.5], 'Neutral cap preserves boost; risky cap does not multiply.');
$base = ['asset_sma_period' => 50, 'dynamic_overrides' => ['algorithm_policy' => ['family' => 'old'], 'rank_hysteresis' => 0.1]];
$global = S::merge($base, ['algorithm_policy' => ['family' => 'new']]);
selectedAssert(!isset($global['dynamic_overrides']['algorithm_policy']) && $global['dynamic_overrides']['rank_hysteresis'] === 0.1, 'Global experiment applies to dynamic too without dropping unrelated overrides.');
$nested = S::merge($base, ['dynamic_overrides' => ['rank_hysteresis' => 0.2]]);
selectedAssert($nested['dynamic_overrides']['algorithm_policy']['family'] === 'old' && $nested['dynamic_overrides']['rank_hysteresis'] === 0.2, 'Nested experiment retains baseline policy.');
selectedAssert(S::hash(['b' => 1, 'a' => ['z' => 1, 'q' => 2]]) === S::hash(['a' => ['q' => 2, 'z' => 1], 'b' => 1]), 'Config hash ignores object key order.');
selectedAssert(S::lag(['a' => true, 'b' => false], false) === ['a' => false, 'b' => true], 'Extra signal lag is backward only.');
$grid = S::catalogue([], [], []);
selectedAssert(count($grid) > 1600, 'Broad finite research coverage.');
$anchors = ['maximum' => ['changes' => $base, 'circuit' => ['drawdown' => 0.18, 'release' => 'confirmed', 'minimum_pause' => 5], 'confirmation' => 'volatility_calm']];
$maps = ['scale' => ['a' => 1.25]];
$d = S::materialize($grid['maximum_stop12'], $anchors, [], [], [], $maps);
selectedAssert($d['changes']['standing_stop_pct'] === 0.12 && $d['circuit'] === $anchors['maximum']['circuit'], 'Adding stop12 must preserve selected portfolio circuit.');
foreach ($grid as $recipe) {
    if (($recipe['source'] ?? '') === 'stops' && $recipe['family'] === 'standing') {
        $d = S::materialize($recipe, $anchors, [], [], [], $maps);
        selectedAssert($d['circuit'] === $anchors['maximum']['circuit'], 'Standing stop variants inherit selected circuit.');
    }
    if (($recipe['source'] ?? '') === 'stops' && $recipe['family'] === 'global_fixed') {
        $d = S::materialize($recipe, $anchors, [], [], [], $maps);
        selectedAssert(!isset($d['circuit']['release']) && $d['confirmation'] === 'none', 'Fixed controller replaces confirmed release instead of accidentally inheriting it.');
    }
}
echo "Selected maximum rebasing, scale caps, policy scopes, stop inheritance and causal lag OK\n";
