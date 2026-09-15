<?php
declare(strict_types=1);
use FulltimeTrading\Research\CandidatePathAttribution as Attribution;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$row = static fn ($d, $start, $end): array => ['date' => $d, 'start_equity' => $start, 'equity' => $end];
$b = [$row('2021-12-30', 100., 110.), $row('2021-12-31', 110., 110.), $row('2022-01-03', 110., 121.)];
$v = [$row('2021-12-30', 100., 110.), $row('2021-12-31', 110., 121.), $row('2022-01-03', 121., 133.1)];
$bc = ['history' => ['2021-12-30' => ['force_cash' => true], '2021-12-31' => ['force_cash' => false], '2022-01-03' => ['force_cash' => false]], 'events' => []];
$vc = $bc; $vc['history']['2021-12-30']['force_cash'] = false;
$a = Attribution::compare($b, $v, $bc, $vc);
$check(abs($a['terminal_money_delta'] - .1) < 1e-12, 'Terminal money ratio.');
$check(abs($a['years'][2021]['relative_factor'] - 1.1) < 1e-12 && abs($a['years'][2022]['relative_factor'] - 1.) < 1e-12, 'Annual factors compound.');
$check($a['first_circuit_divergence']['session'] === '2021-12-31' && $a['first_circuit_divergence']['decided_at_close'] === '2021-12-30', 'No same-day attribution lookahead.');
$check($a['base_forced_cash_sessions'] === 1 && $a['variant_forced_cash_sessions'] === 0, 'Prior-close restriction count.');
$check(abs($a['prior_close_circuit_groups']['only_base_forced_cash']['relative_factor'] - 1.1) < 1e-12, 'Group contribution.');
$check(abs(array_product(array_column($a['years'], 'relative_factor')) - $a['reconciled_relative_factor']) < 1e-12, 'Exact yearly reconciliation.');
foreach (['calendar', 'cashflow', 'capital', 'zero', 'history', 'count'] as $fault) {
    $bad = $v; $c = $vc;
    switch ($fault) {
        case 'calendar': $bad[1]['date'] = '2021-12-29'; break;
        case 'cashflow': $bad[1]['start_equity'] = 115.; break;
        case 'capital': $bad[0]['start_equity'] = 101.; break;
        case 'zero': $bad[0]['equity'] = 0.; break;
        case 'history': unset($c['history']['2021-12-31']); break;
        case 'count': array_pop($bad); break;
    }
    try { Attribution::compare($b, $bad, $bc, $c); } catch (RuntimeException|InvalidArgumentException) { $check(true, $fault); continue; }
    $check(false, 'Accepted invalid ' . $fault);
}
echo "Candidate path attribution: $n assertions PASS\n";
