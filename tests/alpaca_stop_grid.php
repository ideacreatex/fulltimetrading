<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AlpacaStopResearchGrid;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\PortfolioCircuitController;
use FulltimeTrading\Research\RthStandingStopAudit;

require dirname(__DIR__) . '/bootstrap.php';
function gridAssert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$bars = $breadth = $vvix = [];
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
    for ($i = 0; $i < 260; $i++) {
        $time = new DateTimeImmutable('2024-01-01T21:00:00Z +' . $i . ' days');
        $price = 100 + $i * 0.5 + sin($i / 7) * 8;
        $bars[$symbol][] = new Bar($symbol, $time, $price, $price + 1, $price - 1, $price, 1000000.0);
        $date = $time->format('Y-m-d');
        $breadth[$date] = ['close' => 50.0]; $vvix[$date] = 90.0;
    }
}
gridAssert(count(AlpacaStopResearchGrid::definitions()) === 203, 'Freeze the distinct hypothesis count.');
$full = AlpacaStopResearchGrid::confirmations($bars, $breadth, $vvix);
foreach (['2024-03-01', '2024-08-09'] as $cut) {
    $prefix = AlpacaStopResearchGrid::confirmations(HybridV4Research::truncateBars($bars, $cut),
        array_filter($breadth, static fn ($d): bool => $d <= $cut, ARRAY_FILTER_USE_KEY),
        array_filter($vvix, static fn ($d): bool => $d <= $cut, ARRAY_FILTER_USE_KEY));
    foreach ($full as $name => $values) {
        gridAssert($prefix[$name] === array_filter($values, static fn ($d): bool => $d <= $cut, ARRAY_FILTER_USE_KEY), 'Confirmation must be prefix causal: ' . $name);
    }
}
$controller = new PortfolioCircuitController(['drawdown' => 0.1, 'pause' => 1]);
$row = static fn ($date, $start, $end): array => ['date' => $date, 'start_equity' => $start, 'equity' => $end, 'equity_low' => $end];
$a = $controller('2024-01-01', [$row('2024-01-01', 100, 80)]);
$b = $controller('2024-01-02', [$row('2024-01-02', 80, 80)]);
$c = $controller('2024-01-03', [$row('2024-01-03', 80, 64)]);
gridAssert($a['force_cash'] && !$b['force_cash'] && $c['force_cash'], 'Repeated losses must re-trigger the newly armed risk epoch.');
gridAssert($controller->report()['history']['2024-01-03']['equity'] === 64.0, 'Two 20% losses compound to 36%, never a reset account.');
$make = static fn ($time, $o, $h, $l, $c): Bar => new Bar('AAA', new DateTimeImmutable($time), $o, $h, $l, $c, 1000.0);
$event = ['date' => '2024-07-03', 'symbol' => 'AAA', 'stop' => 95.0, 'fill' => 95.0, 'kind' => 'stop_level'];
$session = ['open' => '09:30', 'close' => '13:00'];
$minutes = [$make('2024-07-03T08:00:00-04:00', 100, 101, 90, 99), $make('2024-07-03T09:30:00-04:00', 100, 101, 99, 100),
    $make('2024-07-03T09:31:00-04:00', 100, 101, 94, 95), $make('2024-07-03T09:32:00-04:00', 92, 94, 90, 93),
    $make('2024-07-03T13:00:00-04:00', 90, 91, 89, 90)];
$audit = RthStandingStopAudit::inspect($event, $minutes, $session);
gridAssert($audit['minutes'] === 3 && $audit['first_touch_at'] === '2024-07-03T09:31:00-04:00', 'Exclude premarket and early-close postmarket prices.');
gridAssert($audit['next_minute_open_proxy'] === 92.0 && $audit['next_minute_vs_daily_fill_bps'] < 0, 'Delayed execution may be worse than the stop.');
$event['stop'] = 89.5;
$audit = RthStandingStopAudit::inspect($event, $minutes, $session);
gridAssert(!$audit['rth_touch'], 'An extended-hours-only breach is not an RTH stop trigger.');
echo "Alpaca stop grid, confirmation prefix, repeated drawdown and RTH audit tests OK\n";
