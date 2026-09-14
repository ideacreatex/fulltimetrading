<?php

declare(strict_types=1);

use FulltimeTrading\Trading\WholeShareSizing as S;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0;
$assert = static function (bool $b, string $why) use (&$n): void { ++$n; if (!$b) { throw new RuntimeException($why); } };
$assert(S::target(1000, [], 'A', 1., ['A' => 100.], 30.) === ['A' => 9], 'Costs must be funded.');
$assert(S::target(99, [], 'A', 1., ['A' => 100.], 30.) === [], 'Cannot buy a fractional share.');
$assert(S::target(1000, ['A' => 1000.], 'A', 1., ['A' => 100.], 30.) === ['A' => 10], 'Unchanged holdings need no extra fee.');
$q = S::target(1000, [], 'A', 1., ['A' => 100.], 30.);
$open = S::execute(1000, [], $q, ['A' => 120.], 30.);
$assert($open['quantities'] === ['A' => 9], 'Opening gap must not retrospectively resize an OPG order.');
$assert(abs($open['cash'] - (1000 - 1080 - 3.24)) < 1e-9, 'Gap costs and cash are conserved.');
for ($nav = 100; $nav <= 10000; $nav += 113) {
    foreach ([0., 30., 60., 100.] as $cost) {
        foreach ([.5, 1., 1.18] as $gross) {
            $prices = ['A' => 97.31, 'B' => 121.7];
            $old = ['B' => $nav * .65];
            $q = S::target((float) $nav, $old, 'A', $gross, $prices, $cost);
            $e = S::execute((float) $nav, ['B' => .65], $q, $prices, $cost);
            $qty = $q['A'] ?? 0;
            $assert($qty * $prices['A'] <= $gross * $e['post_cost_nav'] + 1e-7, 'Whole-share target envelope.');
            $feesNext = $cost / 10000 * ($old['B'] + ($qty + 1) * $prices['A']);
            $assert(($qty + 1) * $prices['A'] > $gross * ($nav - $feesNext), 'Target is largest permissible whole count.');
            $assert(abs($e['post_cost_nav'] - ($e['cash'] + $qty * $prices['A'])) < 1e-7, 'NAV conservation.');
        }
    }
}
foreach ([[NAN, [], null, 0., [], 30.], [1000., [], 'A', 1., ['A' => 0], 30.], [1000., [], 'A', 1.4, ['A' => 100.], 30.]] as $args) {
    try { S::target(...$args); $assert(false, 'Invalid input accepted.'); } catch (InvalidArgumentException) { $assert(true, 'Rejected invalid input.'); }
}
echo "whole_share_sizing: {$n} assertions PASS\n";
