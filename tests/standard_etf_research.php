<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\StandardEtfResearch as S;

require dirname(__DIR__) . '/bootstrap.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$cases = S::definitions();
$assert(count($cases) === 108, 'Exactly 108 prespecified variants.');
$r = S::rebalance(30000, [], ['SPY' => 100.0], ['SPY' => 1.0], .003);
$assert(abs($r['shares']['SPY'] - 30000 / 100.3) < 1e-9 && abs($r['cash']) < 1e-9, 'Entry fees are funded from cash, not hidden borrowing.');
$exit = S::rebalance($r['cash'], $r['shares'], ['SPY' => 110.0], [], .003);
$assert(abs($exit['cash'] - 30000 / 100.3 * 110 * .997) < 1e-8, 'Roundtrip fees reconcile.');
$mixed = S::rebalance(0, ['SPY' => 300.0], ['SPY' => 100.0, 'QQQ' => 200.0], ['SPY' => .25, 'QQQ' => .50], .003);
$assert(abs($mixed['cash'] + $mixed['shares']['SPY'] * 100 + $mixed['shares']['QQQ'] * 200 + $mixed['cost'] - 30000) < 1e-8, 'Multisymbol rotation conserves equity.');
foreach ([[['SPY' => -1.0], .003], [['SPY' => 1.01], .003], [['SPY' => NAN], .003], [['SPY' => 1.0], NAN]] as [$target, $fee]) {
    $threw = false; try { S::rebalance(30000, [], ['SPY' => 100.0], $target, $fee); } catch (InvalidArgumentException) { $threw = true; }
    $assert($threw, 'Invalid risk/fees rejected.');
}
$symbols = ['SHY', 'SPY'];
foreach ($cases as $c) { $symbols = array_merge($symbols, $c['symbols']); }
$bars = [];
foreach (array_values(array_unique($symbols)) as $k => $symbol) {
    $price = 100.0;
    for ($i = 0; $i < 520; $i++) {
        $time = new DateTimeImmutable('2020-01-01T21:00:00Z +' . $i . ' days');
        $open = $price * (1 + .001 * sin($i + $k));
        $price = $open * (1.001 + .009 * sin($i / 10.0 + $k));
        $bars[$symbol][] = new Bar($symbol, $time, $open, max($open, $price) * 1.001, min($open, $price) * .999, $price, 1e7);
    }
}
$cut = '2021-03-01';
$short = $shock = $bars;
foreach ($bars as $s => $series) {
    $short[$s] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= $cut));
    foreach ($series as $i => $b) { if (D::session($b) > $cut) { $shock[$s][$i] = new Bar($s, $b->time, $b->open * 10, $b->high * 10, $b->low * 10, $b->close * 10, $b->volume); } }
}
$engine = new S($bars); $prefixEngine = new S($short); $shockEngine = new S($shock);
$familiesWithOrders = [];
foreach ($cases as $id => $c) {
    $full = $engine->run($c, '2020-10-01', '2021-05-01');
    $prefix = $prefixEngine->run($c, '2020-10-01', $cut);
    $assert($prefix['curve'] === array_values(array_filter($full['curve'], static fn ($r): bool => $r['date'] <= $cut)), 'Causal curve prefix: ' . $id);
    $assert($prefix === $shockEngine->run($c, '2020-10-01', $cut), 'Future shocks cannot alter signals, sizing or orders: ' . $id);
    $assert($full['curve'][0]['equity'] === 30000.0 && $full['curve'][0]['turnover'] === 0.0, 'Fresh start must not trade pre-start signals.');
    foreach ($full['orders'] as $order) { $assert($order['signal_date'] < $order['date'], 'No same-close execution.'); $familiesWithOrders[$c['family']] = true; }
    $assert($full['cash'] >= 0 && $full['orders_submitted'] === 0, 'No borrowing or broker orders.');
}
$assert(count($familiesWithOrders) === 5, 'All five families actually trade in the fixture.');
$missing = $bars; unset($missing['QQQ'][350]); $missing['QQQ'] = array_values($missing['QQQ']);
$threw = false;
try { (new S($missing))->run($cases['vol_QQQ_20_10'], '2020-10-01', '2021-05-01'); } catch (RuntimeException) { $threw = true; }
$assert($threw, 'Internal data holes fail closed.');
echo "108 configurations: cost/cash conservation, chronological execution, prefix/future mutation, coverage and research-only tests OK\n";
