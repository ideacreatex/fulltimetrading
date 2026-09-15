<?php
declare(strict_types=1);
use FulltimeTrading\Research\CandidateInteractionAssessment as A;
use FulltimeTrading\Research\CandidateInteractionStudy as I;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$sessions = ['2021-03-25' => true]; $gap = ['date' => '2021-03-25', 'kind' => 'gap_open', 'stop' => 62.744, 'fill' => 62.37];
$check(A::validStopEvent($gap, $sessions), 'Gap stop has no separate intraday notional.');
$check(!A::validStopEvent(array_replace($gap, ['kind' => 'stop_level']), $sessions), 'Intraday stop requires positive notional.');
$check(A::validStopEvent(array_replace($gap, ['kind' => 'stop_level', 'notional' => 100.]), $sessions), 'Intraday notional validated.');
$check(!A::validStopEvent(array_replace($gap, ['fill' => 63.]), $sessions), 'Long stop cannot fill above historical trigger in this model.');
$check(!A::validStopEvent(array_replace($gap, ['date' => '2099-01-01']), $sessions), 'Out-of-window stop rejected.');
$check(!A::validStopEvent(array_replace($gap, ['fill' => NAN]), $sessions), 'Nonfinite stop fill rejected.');
$check(!A::validStopEvent(array_replace($gap, ['kind' => 'stop_level', 'notional' => -1.]), $sessions), 'Negative notional rejected.');
$check(!A::validStopEvent([], $sessions), 'Missing stop event rejected without inventing values.');
$m = ['return' => 1., 'cagr' => .2, 'max_drawdown' => -.2, 'max_gross_bound' => 1.2, 'turnover' => 10.]; $results = [];
foreach (I::cases() as $id => $_) { foreach (I::conditions() as $scenario => $_) { foreach ([30, 60] as $cost) { $results[$id][$scenario . '__' . $cost] = $m; } } }
$result = A::assess($results, 1000.); $check($result['money_drawdown_dominators'] === [] && $result['capital_nonworse'] === [], 'Identical results are not improvements.');
$better = 'vvix_confirmation_110';
foreach ($results[$better] as &$metric) { $metric['return'] = 1.2; $metric['cagr'] = .25; $metric['max_drawdown'] = -.18; }
unset($metric); $r = A::assess($results, 1000.);
$check($r['money_drawdown_dominators'] === [$better] && $r['rows'][$better]['gross_nonhigher_all_eight'], 'Eight-condition dominance recognized.');
$d = $r['rows'][$better]['deltas']['continuous__30'];
$check(abs($d['terminal_equity'] - 2200.) < 1e-9 && abs($d['capital_delta_dollars'] - 200.) < 1e-9 && abs($d['capital_delta_pct'] - 10.) < 1e-9, 'Principal, dollar delta and relative percent distinguished.');
$check(abs($d['drawdown_improvement_pp'] - 2.) < 1e-9, 'Drawdown improvement has correct sign.');
$check($r['rows'][$better]['neighbors']['vvix_confirmation_105']['factor'] === 'vvix', 'One-factor comparison holds remaining factors fixed.');
$results[$better]['early2017__60']['return'] = .9; $r = A::assess($results, 1000.);
$check($r['money_drawdown_dominators'] === [] && !$r['rows'][$better]['capital_nonworse_all_eight'], 'Early-regime loss cannot be concealed by seven wins.');
$check($r['rows'][$better]['drawdown_nonworse_all_eight'], 'Risk benefit remains visible despite lower capital.');
foreach (['missing_case', 'missing_condition', 'nan', 'negative_capital', 'invalid_dd'] as $kind) {
    $x = $results; $capital = 1000.;
    if ($kind === 'missing_case') { unset($x[$better]); }
    if ($kind === 'missing_condition') { unset($x[$better]['early2017__60']); }
    if ($kind === 'nan') { $x[$better]['early2017__60']['return'] = NAN; }
    if ($kind === 'negative_capital') { $capital = -1.; }
    if ($kind === 'invalid_dd') { $x[$better]['early2017__60']['max_drawdown'] = .1; }
    try { A::assess($x, $capital); } catch (Throwable) { $check(true, 'Invalid comparisons rejected.'); continue; }
    $check(false, 'Invalid comparison accepted.');
}
echo "Candidate interaction assessment: $n assertions PASS\n";
