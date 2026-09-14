<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\CrossAssetResearch as X;
use FulltimeTrading\Research\HybridV4Research as H;

require dirname(__DIR__) . '/bootstrap.php';
function crossAssert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
$symbols = array_values(array_unique(array_merge(['SPY', 'TLT', 'UUP'], ...array_values(X::RATIOS))));
$bars = $cboe = $breadth = $vvix = [];
for ($i = 0; $i < 310; $i++) {
    $time = new DateTimeImmutable('2023-01-01T21:00:00Z +' . $i . ' days'); $date = $time->format('Y-m-d');
    foreach ($symbols as $j => $s) {
        $price = 100 + $i * 0.1 + sin($i / 7) * ($j % 3);
        $bars[$s][] = new Bar($s, $time, $price, $price + 1, $price - 1, $price, 1000000.0);
    }
    foreach (['VIX', 'VIX9D', 'OVX', 'GVZ'] as $s) { $cboe[$s][$date] = 20 + sin($i / 5); }
    $breadth[$date] = ['close' => 50.0]; $vvix[$date] = 90.0;
}
$f = X::features($bars, $cboe, $breadth, $vvix);
crossAssert(count(X::definitions()) === 326, 'Frozen economic grid count.');
$cut = '2023-10-01';
$truncate = static fn ($a): array => array_filter($a, static fn ($d): bool => $d <= $cut, ARRAY_FILTER_USE_KEY);
$prefix = X::features(H::truncateBars($bars, $cut), array_map($truncate, $cboe), $truncate($breadth), $truncate($vvix));
foreach ($f as $name => $values) { crossAssert($prefix[$name] === $truncate($values), 'Future-dependent feature: ' . $name); }
$dates = array_keys($breadth);
crossAssert($f['OVX'][$dates[251]] === null && is_float($f['OVX'][$dates[252]]), 'Percentile reference requires 252 prior observations.');
crossAssert($f['short_vol_term'][$dates[5]] === 1.0, 'Vol term ratio units.');
$gap = $cboe;
unset($gap['OVX'][$dates[290]]);
try { X::features($bars, $gap, $breadth, $vvix); throw new LogicException('Unlisted gap accepted.'); }
catch (RuntimeException $e) { crossAssert(str_contains($e->getMessage(), 'Missing Cboe'), 'Wrong missing Cboe error.'); }
$withGap = X::features($bars, $gap, $breadth, $vvix, ['OVX' => [$dates[290]]]);
crossAssert($withGap['OVX'][$dates[290]] === null, 'Declared outage stays null, never backfilled.');
$d = ['anchor' => 'maximum', 'family' => 'test', 'feature' => 'test', 'operator' => 'gt', 'threshold' => 0.0, 'cap' => 0.5];
$base = ['external_daily_scale' => ['a' => 1.25, 'b' => 1.25, 'c' => 1.25]];
$changes = X::changes($base, $d, ['test' => ['a' => 0, 'b' => 1, 'c' => null]]);
crossAssert($changes['external_daily_scale'] === ['a' => 1.25, 'b' => 0.5, 'c' => 0.5], 'Neutral feature preserves original boost, risky/warmup caps fail closed.');
try { X::changes($base, $d, ['test' => ['a' => 0]]); throw new LogicException('Missing date accepted.'); }
catch (RuntimeException $e) { crossAssert(str_contains($e->getMessage(), 'Missing causal'), 'Wrong failure for missing history.'); }
echo "Cross-asset feature prefixes, grid, percentile warmup and risk cap tests OK\n";
