<?php
declare(strict_types=1);
use FulltimeTrading\Research\DeployedCandidateComparison as C;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $v, string $why) use (&$n): void { ++$n; if (!$v) { throw new RuntimeException($why); } };
$m = ['return' => 2., 'cagr' => .4, 'max_drawdown' => -.2, 'max_gross_bound' => 1.25, 'turnover' => 10.];
$control = array_fill_keys(C::CONDITIONS, $m); $cases = ['deployed' => $control, 'identical' => $control,
    'better' => array_fill_keys(C::CONDITIONS, array_replace($m, ['return' => 3., 'cagr' => .5, 'max_drawdown' => -.15])),
    'only_one' => $control, 'less_risk' => array_fill_keys(C::CONDITIONS, array_replace($m, ['return' => 1.5, 'max_drawdown' => -.1, 'max_gross_bound' => 1.])),
    'more_leverage' => array_fill_keys(C::CONDITIONS, array_replace($m, ['return' => 3., 'max_gross_bound' => 1.5]))];
$cases['only_one']['continuous__30']['return'] = 10.; $cases['only_one']['continuous__60']['return'] = 1.;
$r = C::compare($cases, 1000.);
$check(!$r['identical']['dominates_control_money_drawdown'] && $r['identical']['same_money_drawdown_as_control'], 'No fake improvements from unchanged outcomes.');
$check($r['better']['dominates_control_money_drawdown'] && $r['better']['gross_nonhigher_all_four'], 'All four conditions improve.');
$check(abs($r['better']['deltas']['continuous__30']['terminal_equity'] - 4000.) < 1e-9, 'Terminal capital includes principal.');
$check(abs($r['better']['deltas']['continuous__30']['terminal_equity_delta'] - 1000.) < 1e-9, 'Correct dollars, not CAGR points.');
$check(abs($r['better']['deltas']['continuous__30']['drawdown_improvement_pp'] - 5.) < 1e-9, 'Less negative drawdown is an improvement.');
$check(!$r['only_one']['dominates_control_money_drawdown'] && !$r['only_one']['money_nonworse_all_four'], 'One spectacular result cannot conceal cost sensitivity.');
$check($r['less_risk']['drawdown_nonworse_all_four'] && !$r['less_risk']['money_nonworse_all_four'], 'User-relevant risk tradeoff remains visible.');
$check($r['more_leverage']['dominates_control_money_drawdown'] && !$r['more_leverage']['gross_nonhigher_all_four'], 'Increasing leverage is disclosed, not called free alpha.');
$check(in_array('better', $r['deployed']['dominated_by'], true) && $r['less_risk']['dominated_by'] === [], 'Pareto frontier includes lower-return defensive alternative.');
foreach (['missing', 'nan', 'bad_dd', 'zero_capital', 'no_control'] as $case) {
    $x = $cases; $capital = 1000.;
    if ($case === 'missing') { unset($x['better']['continuous__60']); }
    if ($case === 'nan') { $x['better']['continuous__60']['cagr'] = NAN; }
    if ($case === 'bad_dd') { $x['better']['continuous__60']['max_drawdown'] = .2; }
    if ($case === 'zero_capital') { $capital = 0.; }
    if ($case === 'no_control') { unset($x['deployed']); }
    try { C::compare($x, $capital); } catch (Throwable) { $check(true, 'Invalid comparison rejected.'); continue; }
    $check(false, 'Invalid comparison accepted.');
}
echo "Deployed candidate comparison: $n assertions PASS\n";
