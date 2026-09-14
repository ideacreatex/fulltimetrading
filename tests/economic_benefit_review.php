<?php

declare(strict_types=1);

use FulltimeTrading\Research\EconomicBenefitReview as R;

require dirname(__DIR__) . '/bootstrap.php';
$n = 0;
$expect = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$b = ['start' => '2023-01-03', 'end' => '2026-09-04', 'initial_equity' => 30000.,
    'terminal_equity' => 554697.9980275457, 'cagr' => 1.2147995601100443, 'max_drawdown' => -.2988764384944924,
    'data_contract' => 'alpaca/sip/all', 'cost_bps' => 30, 'calendar_sha256' => str_repeat('a', 64)];
$c = array_replace($b, ['terminal_equity' => 778090.4375689363, 'cagr' => 1.4288218988488595, 'max_drawdown' => -.23588218391231575]);
$r = R::compare($b, $c);
$expect($r['material_capital_benefit'] && $r['material_risk_benefit'], 'The selected candidate has both benefits from fresh 2023.');
$expect(abs($r['drawdown_reduction_pp'] - 6.299425458217665) < 1e-9, 'Drawdown improvement is percentage points.');
$expect($r['strictly_dominates'], 'Both axes improve.');
$expect(!$r['execution_authorized'] && !$r['live_authorized'], 'Economic interest cannot open the broker gateway.');
$c = array_replace($b, ['terminal_equity' => $b['terminal_equity'] * .998, 'cagr' => $b['cagr'] - .0006, 'max_drawdown' => -.235]);
$r = R::compare($b, $c);
$expect($r['economically_interesting'] && !$r['strictly_dominates'], 'Lower drawdown can be useful despite a small return decrease.');
$expect($r['tradeoff'] === 'less_ending_money_for_risk_reduction', 'Return sacrifice is disclosed.');
$c = array_replace($b, ['terminal_equity' => $b['terminal_equity'] * 1.11, 'max_drawdown' => -.35]);
$expect(R::compare($b, $c)['tradeoff'] === 'more_drawdown_for_money', 'Money benefit does not hide risk deterioration.');
$expect(!R::compare($b, $b)['economically_interesting'], 'No change is not an improvement.');
$expect(abs(R::cagr(100, 121, 2) - .1) < 1e-12, '121 from 100 over two years means CAGR 10 percent.');
$expect(abs(R::cagr(100, 81, 2) + .1) < 1e-12, 'Negative CAGR.');
$expect(R::cagr(100, 0, 2) === -1.0, 'Total loss is minus 100 percent CAGR.');
foreach (['start', 'end', 'initial_equity', 'data_contract', 'cost_bps', 'calendar_sha256'] as $key) {
    $c = $b; $c[$key] = 'different';
    try { R::compare($b, $c); $expect(false, 'Expected incompatible scenario rejection.'); }
    catch (InvalidArgumentException) { $expect(true, 'Rejected ' . $key); }
}
foreach ([['cagr', NAN], ['terminal_equity', 0], ['max_drawdown', .2], ['max_drawdown', -1], ['cagr', INF]] as [$key, $value]) {
    $c = $b; $c[$key] = $value;
    try { R::compare($b, $c); $expect(false, 'Expected invalid metric rejection.'); }
    catch (InvalidArgumentException) { $expect(true, 'Rejected invalid ' . $key); }
}
echo "economic_benefit_review: {$n} assertions PASS\n";
