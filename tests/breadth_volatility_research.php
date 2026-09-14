<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveRotationBacktester;
use FulltimeTrading\Research\BreadthVolatilityGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;

require dirname(__DIR__) . '/bootstrap.php';
function breadthAssert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$series = [];
foreach ([50, 11, 8, 12, 22, 79, 80, 85, 75, 0, 100] as $i => $close) {
    $date = (new DateTimeImmutable('2024-01-01 +' . $i . ' days'))->format('Y-m-d');
    $series[$date] = ['open' => (float) $close, 'high' => (float) $close, 'low' => (float) $close, 'close' => (float) $close];
}
B::validateBreadth($series, array_keys($series));
$events = B::events($series);
breadthAssert($events['low_touch']['2024-01-02'] && !$events['low_touch']['2024-01-03'], 'Count first touch, not every day in zone; exact 11 included.');
breadthAssert($events['low_reclaim11']['2024-01-04'] && $events['low_reclaim20']['2024-01-05'], 'Reclaim occurs after the close crosses the threshold.');
breadthAssert($events['high_touch']['2024-01-07'] && $events['high_hold2']['2024-01-08'] && $events['high_fade80']['2024-01-09'], 'Exact 80, confirmation and fade are distinct.');
$window = B::windowScale($events['low_touch'], 2, 1.25);
breadthAssert($window['2024-01-02'] === 1.25 && $window['2024-01-03'] === 1.25 && $window['2024-01-04'] === 1.0, 'Sizing window uses known sessions only.');
$prefixChecks = 0;
for ($n = 2; $n <= count($series); $n++) {
    $prefix = array_slice($series, 0, $n, true);
    foreach (B::events($prefix) as $id => $flags) {
        breadthAssert($flags === array_slice($events[$id], 0, $n, true), 'Future breadth cannot alter an earlier event.');
        $prefixChecks++;
    }
}
$bad = $series; unset($bad['2024-01-03']);
$reject = false;
try { B::validateBreadth($bad, array_keys($series)); } catch (RuntimeException) { $reject = true; }
breadthAssert($reject, 'Missing breadth must fail, not forward fill.');
$bars = [];
foreach ([100, 200, 200, 100, 100, 100] as $i => $price) {
    $bars[] = new Bar('SVXY', new DateTimeImmutable('2024-01-01T21:00:00Z +' . $i . ' days'), $price, $price, $price, $price, 1000);
}
$signals = array_fill_keys(array_slice(array_keys($series), 0, 6), false);
$signals['2024-01-01'] = true;
$signals['2024-01-04'] = true;
$run = B::eventReplay($bars, $signals, 1, 0, '2024-01-01', '2024-01-06');
breadthAssert(count($run['trades']) === 2, 'Can re-enter after an exit, no int/float zero mismatch.');
breadthAssert($run['trades'][0]['entry'] === '2024-01-02' && $run['trades'][0]['entry_price'] === 200.0, 'Cannot profit from the signal-to-next-open gap before entry.');
breadthAssert($run['curve'][1]['equity'] === 30000.0, 'No same-session fill or manufactured gap return.');
$cost = B::eventReplay($bars, $signals, 1, 30, '2024-01-01', '2024-01-06');
breadthAssert(abs(end($cost['curve'])['equity'] - 30000 * ((1 - 0.003) / (1 + 0.003)) ** 2) < 1e-8, 'Both sides pay costs; exact cash conservation.');
$prefix = B::eventReplay(array_slice($bars, 0, 4), array_slice($signals, 0, 4, true), 1, 30, '2024-01-01', '2024-01-04');
breadthAssert($prefix['curve'] === array_slice($cost['curve'], 0, 4), 'Event replay is prefix invariant.');
$a = ['date' => '2024-01-01', 'start_equity' => 200.0, 'equity' => 220.0, 'equity_low' => 190.0, 'equity_high' => 230.0, 'turnover' => 0.5];
$b = ['date' => '2024-01-01', 'start_equity' => 100.0, 'equity' => 90.0, 'equity_low' => 80.0, 'equity_high' => 110.0, 'turnover' => 1.0];
$mixed = B::mix([$a], [$b], 0.1)[0];
breadthAssert(abs($mixed['equity'] - 207.0) < 1e-10 && abs($mixed['turnover'] - 100.0 / 190.0) < 1e-10, 'Static sleeves preserve wealth and dollar-weight turnover after capital drifts.');
$definitions = BreadthVolatilityGrid::definitions();
breadthAssert(count($definitions) >= 200, 'Broad declared risk/indicator grid.');
$fixture = $asset = $vvix = [];
for ($i = 0; $i < 300; $i++) {
    $date = (new DateTimeImmutable('2023-01-01 +' . $i . ' days'))->format('Y-m-d');
    $close = 50.0 + 40 * sin($i / 5);
    $fixture[$date] = ['open' => $close, 'high' => $close, 'low' => $close, 'close' => $close];
    $vvix[$date] = 100.0 + 35 * sin($i / 7);
    $price = 100.0 + $i / 10 + 10 * sin($i / 10);
    $asset[] = new Bar('SVXY', new DateTimeImmutable($date . 'T21:00:00Z'), $price, $price, $price, $price, 1000);
}
foreach ($definitions as $id => $definition) {
    $full = BreadthVolatilityGrid::changes($definition, $fixture, $vvix, $asset);
    $prefix = BreadthVolatilityGrid::changes($definition, array_slice($fixture, 0, 280, true), array_slice($vvix, 0, 280, true), array_slice($asset, 0, 280));
    if (isset($full['external_daily_scale'])) {
        breadthAssert($prefix['external_daily_scale'] === array_slice($full['external_daily_scale'], 0, 280, true), 'No future values in grid: ' . $id);
        $prefixChecks++;
    }
}
foreach ([NAN, INF, -0.1, 2.1] as $value) {
    $reject = false;
    try { new AdaptiveRotationBacktester(['universe' => ['SPY'], 'external_daily_scale' => ['2024-01-01' => $value]]); }
    catch (InvalidArgumentException) { $reject = true; }
    breadthAssert($reject, 'Invalid research risk scale rejected.');
}
echo 'Breadth/volatility tests OK; ', $prefixChecks, " prefix checks; ", count($definitions), " hybrid hypotheses\n";
