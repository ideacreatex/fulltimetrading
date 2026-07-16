<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\FixedShareExecutionEquityBuilder;

require __DIR__ . '/../bootstrap.php';

$rows = [[
    'symbol' => 'TEST',
    'shares' => 10.0,
    'minute_entry_time' => '2026-07-13T13:30:00+00:00',
    'minute_entry' => 100.0,
    'modeled_entry_cost' => 1.0,
    'minute_partial_time' => '2026-07-14T14:00:00+00:00',
    'minute_partial_price' => 110.0,
    'minute_partial_shares' => 5.0,
    'modeled_partial_costs' => 0.5,
    'minute_exit_time' => '2026-07-15T13:30:00+00:00',
    'minute_exit' => 90.0,
    'modeled_exit_cost' => 0.5,
]];
$curve = (new FixedShareExecutionEquityBuilder())->build(
    ['2026-07-13', '2026-07-14', '2026-07-15'],
    $rows,
    ['TEST' => ['2026-07-13' => 102.0, '2026-07-14' => 108.0, '2026-07-15' => 90.0]],
    1000.0,
);

$expected = [1019.0, 1088.5, 998.0];
foreach ($expected as $index => $equity) {
    if (abs((float) ($curve[$index]['equity'] ?? 0.0) - $equity) > 1.0e-9) {
        throw new RuntimeException('Fixed-share execution equity booked an event on the wrong session.');
    }
}

echo "Fixed-share execution equity OK\n";
