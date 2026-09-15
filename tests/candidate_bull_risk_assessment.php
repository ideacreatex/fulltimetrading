<?php
declare(strict_types=1);
use FulltimeTrading\Research\CandidateBullRiskAssessment as A;
use FulltimeTrading\Research\CandidateBullRiskStudy as B;
use FulltimeTrading\Research\CandidateInteractionStudy as I;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$metric = ['return' => 1., 'cagr' => .2, 'max_drawdown' => -.2, 'max_gross_bound' => 1.2, 'turnover' => 10.]; $results = [];
foreach (B::cases() as $id => $_) { foreach (I::conditions() as $scenario => $_) { foreach ([30, 60] as $cost) { $results[$id][$scenario . '__' . $cost] = $metric; } } }
$id = 'bull_v105_ma50_boost105'; $a = A::assess($results, 1000.);
$check(!$a['rows'][$id]['comparisons']['deployed']['money_drawdown_dominates'], 'Equal results not improvements.');
$check($a['rows'][$id]['comparisons']['anchor']['reference'] === 'vvix_confirmation_105', 'Compare against own VVIX anchor.');
$check($a['rows'][$id]['comparisons']['constant_risk']['reference'] === 'mix_v105_s50_r110_b10', 'Constant size control holds VVIX fixed.');
$check($a['rows'][$id]['neighbors']['bull_v105_ma50_boost110']['factor'] === 'boost', 'Size neighbors.');
$check($a['rows'][$id]['neighbors']['bull_v105_ma200_boost105']['factor'] === 'window', 'Trend neighbors.');
$check(!isset($a['rows'][$id]['neighbors']['bull_v100_ma200_boost110']), 'Multiple changed factors not a neighbor.');
foreach ($results[$id] as &$m) { $m['return'] = 1.2; $m['cagr'] = .25; $m['max_drawdown'] = -.18; }
unset($m); $a = A::assess($results, 1000.); $c = $a['rows'][$id]['comparisons']['deployed']; $d = $c['deltas']['continuous__30'];
$check($c['money_drawdown_dominates'], 'All-condition improvement.');
$check(abs($d['terminal_equity'] - 2200.) < 1e-9 && abs($d['capital_delta_dollars'] - 200.) < 1e-9 && abs($d['capital_delta_pct'] - 10.) < 1e-9, 'Money denominators.');
$check(abs($d['drawdown_improvement_pp'] - 2.) < 1e-9, 'DD improvement sign.');
$results[$id]['early2017__60']['return'] = .8; $a = A::assess($results, 1000.); $c = $a['rows'][$id]['comparisons']['deployed'];
$check(!$c['money_drawdown_dominates'] && !$c['capital_nonworse_all_eight'] && $c['drawdown_nonworse_all_eight'], 'Earlier loss retained and independent risk benefit visible.');
$check(abs($c['worst_capital_delta_pct'] + 10.) < 1e-9, 'Worst period not averaged away.');
$results['vvix_confirmation_105']['continuous__30']['return'] = 1.3; $a = A::assess($results, 1000.);
$check($a['rows'][$id]['comparisons']['deployed']['deltas']['continuous__30']['capital_delta_dollars'] > 0
    && $a['rows'][$id]['comparisons']['anchor']['deltas']['continuous__30']['capital_delta_dollars'] < 0, 'VVIX benefit cannot be attributed to sizing.');
foreach (['missing_case', 'extra_case', 'missing_condition', 'nan', 'capital', 'dd', 'gross', 'ruin'] as $kind) {
    $x = $results; $capital = 1000.;
    if ($kind === 'missing_case') { unset($x[$id]); }
    if ($kind === 'extra_case') { $x['undeclared'] = $x[$id]; }
    if ($kind === 'missing_condition') { unset($x[$id]['early2017__30']); }
    if ($kind === 'nan') { $x[$id]['early2017__30']['return'] = NAN; }
    if ($kind === 'capital') { $capital = 0.; }
    if ($kind === 'dd') { $x[$id]['early2017__30']['max_drawdown'] = .1; }
    if ($kind === 'gross') { $x[$id]['early2017__30']['max_gross_bound'] = -1.; }
    if ($kind === 'ruin') { $x[$id]['early2017__30']['return'] = -1.; }
    try { A::assess($x, $capital); } catch (InvalidArgumentException) { $check(true, 'Invalid matrix rejected.'); continue; }
    $check(false, 'Invalid matrix accepted.');
}
$check($a['deployment_authority'] === false && $a['independent_holdout'] === false, 'Offline research never authorizes deployment.');
echo "Candidate bull-risk assessment: $n assertions PASS\n";
