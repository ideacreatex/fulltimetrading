<?php

declare(strict_types=1);

use FulltimeTrading\Research\ResearchTradeLedger;

require dirname(__DIR__) . '/bootstrap.php';
function ledgerAssert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$row = static function (string $date, ?string $symbol, ?string $stopSymbol = null, string $kind = 'stop_level'): array {
    return ['date' => $date, 'holding' => $symbol, 'standing_stop_event' => $stopSymbol === null ? null
        : ['date' => $date, 'symbol' => $stopSymbol, 'kind' => $kind]];
};
$curves = ['one' => [$row('2023-12-28', null), $row('2023-12-29', 'AAA'), $row('2024-01-02', 'AAA'),
    $row('2024-01-03', 'BBB'), $row('2024-01-04', 'BBB'), $row('2024-01-05', null, 'CCC'), $row('2024-02-01', 'DDD')],
    'two' => [$row('2023-12-28', null), $row('2023-12-29', 'AAA'), $row('2024-01-02', 'AAA'),
        $row('2024-01-03', 'AAA'), $row('2024-01-04', null, 'AAA', 'gap_open'), $row('2024-01-05', null), $row('2024-02-01', null)]];
$ledger = ResearchTradeLedger::fromSleeveCurves($curves);
ledgerAssert($ledger['portfolio']['annual_closed'] === [2023 => 0, 2024 => 3], 'Count completed ticker positions by exit year.');
ledgerAssert($ledger['sleeves']['closed_total'] === 4, 'Simultaneous same-ticker sleeve trades are separately auditable.');
ledgerAssert($ledger['portfolio']['opened_total'] === 4 && count($ledger['portfolio']['open_at_end']) === 1, 'Do not force-close the last open position.');
$closed = array_column($ledger['portfolio']['closed'], null, 'symbol');
ledgerAssert($closed['AAA']['entry_date'] === '2023-12-29' && $closed['AAA']['exit_date'] === '2024-01-04', 'A partial sleeve exit does not close the aggregate ticker.');
ledgerAssert($closed['CCC']['entry_date'] === '2024-01-05' && $closed['CCC']['exit_phase'] === 'intraday_stop', 'Same-day entry/stop is one completed trade.');
$prefix = ResearchTradeLedger::fromSleeveCurves(array_map(static fn ($r): array => array_slice($r, 0, 5), $curves));
ledgerAssert($prefix['portfolio']['closed'] === array_values(array_filter($ledger['portfolio']['closed'], static fn ($t): bool => $t['exit_date'] <= '2024-01-04')), 'Later rows cannot manufacture prefix exits.');
$swap = ['a' => [$row('2024-01-01', null), $row('2024-01-02', 'AAA'), $row('2024-01-03', null), $row('2024-01-04', null)],
    'b' => [$row('2024-01-01', null), $row('2024-01-02', null), $row('2024-01-03', 'AAA'), $row('2024-01-04', null)]];
$swapCount = ResearchTradeLedger::fromSleeveCurves($swap);
ledgerAssert($swapCount['portfolio']['closed_total'] === 1 && $swapCount['sleeves']['closed_total'] === 2, 'Same-open ownership changes keep aggregate exposure continuous.');
$sameDay = [$row('2024-01-01', null), $row('2024-01-02', null, 'AAA')];
$sameDayCount = ResearchTradeLedger::fromSleeveCurves(['a' => $sameDay, 'b' => $sameDay]);
ledgerAssert($sameDayCount['portfolio']['closed_total'] === 1 && $sameDayCount['sleeves']['closed_total'] === 2, 'Daily-close-only counting must not miss intraday round trips.');
$bad = $curves; $bad['two'][4]['standing_stop_event']['symbol'] = 'WRONG';
$rejected = false;
try { ResearchTradeLedger::fromSleeveCurves($bad); } catch (RuntimeException) { $rejected = true; }
ledgerAssert($rejected, 'Contradictory stop records must fail closed.');
echo "Research trade ledger tests OK\n";
