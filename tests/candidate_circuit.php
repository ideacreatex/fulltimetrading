<?php

declare(strict_types=1);

use FulltimeTrading\Paper\CandidateCircuit;
use FulltimeTrading\Research\PortfolioCircuitController;

require dirname(__DIR__) . '/bootstrap.php';
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$config = ['drawdown' => .18, 'release' => 'confirmed', 'minimum_pause' => 5, 'initial_scale' => 1];
$days = $confirmations = []; $equity = 30000.;
for ($i = 0; $i < 150; ++$i) {
    $date = (new DateTimeImmutable('2026-01-01'))->modify('+' . $i . ' weekdays')->format('Y-m-d');
    $start = $equity;
    $equity *= match ($i % 25) { 5 => .79, 17 => .76, default => 1.01 };
    $rows = [];
    for ($j = 0; $j < 12; ++$j) { $rows['s' . $j] = ['date' => $date, 'start_equity' => $start / 12, 'equity' => $equity / 12, 'equity_low' => $equity / 12]; }
    $days[$date] = $rows; $confirmations[$date] = $i % 7 === 0;
}
$reference = new PortfolioCircuitController($config, $confirmations); $state = null;
foreach ($days as $date => $rows) {
    $expected = $reference($date, $rows);
    // A new instance plus JSON roundtrip models a fresh process on every market close.
    $actual = (new CandidateCircuit($config))->advance($state, $date, $rows, $confirmations[$date]);
    $check($actual['feedback'] === $expected, 'Persistent feedback differs from frozen controller on ' . $date);
    $check($actual['report'] === $reference->report(), 'Persistent circuit history differs from frozen controller.');
    $repeated = (new CandidateCircuit($config))->advance($actual['state'], $date, $rows, $confirmations[$date]);
    $check($repeated === $actual, 'Repeated same close must not shorten the pause.');
    $state = json_decode(json_encode($actual['state'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}
$check(count($actual['report']['events']) > 5, 'Fixture exercises multiple pauses and releases.');
foreach ($actual['report']['events'] as $event) {
    if ($event['event'] === 'release_next_open') {
        $check($event['elapsed_cash_sessions'] >= 5 && $event['confirmation'] === true, 'Release requires five market observations and confirmation.');
    }
}
$reject = static function (callable $fn) use ($check): void { try { $fn(); } catch (Throwable) { $check(true, 'Rejected bad state.'); return; } $check(false, 'Accepted bad state.'); };
$reject(fn () => (new CandidateCircuit($config))->advance($state, $date, $rows, !$confirmations[$date]));
$reject(fn () => (new CandidateCircuit(array_replace($config, ['minimum_pause' => 1])))->advance($state, $date, $rows, true));
$reject(fn () => (new CandidateCircuit($config))->advance(null, $date, array_slice($rows, 0, 11), true));
echo "candidate_circuit: {$n} assertions PASS\n";
