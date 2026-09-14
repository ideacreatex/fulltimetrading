<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\DailyDataAudit;

require dirname(__DIR__) . '/bootstrap.php';
function dataAuditAssert(bool $ok, string $why): void
{
    if (!$ok) { throw new RuntimeException($why); }
}
$a = ['SPY' => [new Bar('SPY', new DateTimeImmutable('2026-09-03T04:00:00Z'), 100, 103, 98, 102, 1000),
    new Bar('SPY', new DateTimeImmutable('2026-09-04T04:00:00Z'), 102, 105, 101, 104, 1200)]];
$b = ['SPY' => [new Bar('SPY', new DateTimeImmutable('2026-09-03T13:30:00Z'), 101, 103, 98, 102, 100),
    new Bar('SPY', new DateTimeImmutable('2026-09-04T13:30:00Z'), 102, 105, 101, 104, 120)]];
dataAuditAssert(DailyDataAudit::encode(DailyDataAudit::decode(DailyDataAudit::encode($a))) === DailyDataAudit::encode($a), 'Snapshot round trip.');
$r = DailyDataAudit::compare($a, $b);
dataAuditAssert($r['matched_bars'] === 2 && $r['missing_candidate'] === [], 'Align New York sessions, not UTC timestamps.');
dataAuditAssert(abs($r['summary']['open']['max'] - 100) < 1e-8, 'Report relative bps.');
dataAuditAssert(abs($r['summary']['volume_ratio']['p50'] - 0.1) < 1e-8, 'IEX volume remains distinct, no implicit normalization.');
$mixed = DailyDataAudit::replaceFields($a, $b, ['volume'], '2026-09-04');
dataAuditAssert($mixed['SPY'][0] === $a['SPY'][0] && $mixed['SPY'][1]->volume === 120.0 && $mixed['SPY'][1]->open === 102.0, 'Field and boundary isolation.');
$missing = $b; array_pop($missing['SPY']);
dataAuditAssert(count(DailyDataAudit::compare($a, $missing)['missing_candidate']) === 1, 'Missing bars reported, not intersected away.');
$reject = false;
try { DailyDataAudit::replaceFields($a, $missing, ['open']); } catch (RuntimeException) { $reject = true; }
dataAuditAssert($reject, 'Cannot silently fill missing candidate sessions.');
$duplicate = $a; $duplicate['SPY'][] = $a['SPY'][1];
$reject = false;
try { DailyDataAudit::indexed($duplicate); } catch (RuntimeException) { $reject = true; }
dataAuditAssert($reject, 'Duplicate sessions rejected.');
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
$replaced = DailyDataAudit::withUniverse($profile, ['IBM', 'COIN', 'IBM']);
dataAuditAssert($replaced['sleeves']['dynamic_loo10']['config']['universe'] === ['COIN', 'IBM'], 'Universe reaches each sleeve.');
dataAuditAssert($replaced['sleeves']['qqq150_ex_crypto']['config']['universe'] === ['IBM'], 'Crypto exclusion preserved.');
dataAuditAssert($profile['universe'] !== $replaced['universe'] && !$replaced['order_submission_enabled'], 'Input profile and execution gates preserved.');
$availability = DailyDataAudit::availableBy(['SPY'], $a, '2020-12-31');
dataAuditAssert($availability['included'] === [] && isset($availability['not_yet_in_this_history']['SPY']), 'Do not manufacture pre-listing prices.');
$reject = false;
try { DailyDataAudit::availableBy(['MISSING'], $a, '2020-12-31'); } catch (RuntimeException) { $reject = true; }
dataAuditAssert($reject, 'Unknown data is not proof of a later IPO.');
$bad = $a;
$bad['SPY'][0] = new Bar('SPY', $a['SPY'][0]->time, 100, 103, 9.8, 102, 1000);
$repair = DailyDataAudit::corroboratedOutliers($bad, $a, $a);
dataAuditAssert(count($repair['events']) === 1 && $repair['events'][0]['field'] === 'low', 'Require two corroborating sources, identify the field.');
dataAuditAssert($repair['bars']['SPY'][0]->low === 98.0 && $bad['SPY'][0]->low === 9.8, 'Do not mutate the source snapshot.');
$repair = DailyDataAudit::corroboratedOutliers($bad, $a, $bad);
dataAuditAssert($repair['events'] === [], 'Disagreement between corroborators is not a correction.');
$affected = DailyDataAudit::missingExecutionBars(['one' => [
    ['date' => '2026-09-01', 'holding' => 'AAA', 'turnover' => 1],
    ['date' => '2026-09-02', 'holding' => 'BBB', 'turnover' => 2],
    ['date' => '2026-09-03', 'holding' => 'BBB', 'turnover' => 0],
]], ['AAA/2026-09-02', 'BBB/2026-09-03', 'CCC/2026-09-01']);
dataAuditAssert(count($affected) === 1 && $affected[0]['symbol'] === 'AAA', 'Check sells as well as buys; unused missing bars must not count as missing executions.');
echo "Daily data audit tests OK\n";
