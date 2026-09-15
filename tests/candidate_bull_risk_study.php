<?php
declare(strict_types=1);
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\CandidateBullRiskStudy as B;
use FulltimeTrading\Research\DeployedCandidateStudy as S;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$cases = B::cases(); $check(count($cases) === 18 && count(array_filter($cases, static fn ($c): bool => $c['reuse_id'] === null)) === 12, '12 new rules and six cached controls.');
$all = $breadth = $vvix = []; $zone = new DateTimeZone('America/New_York');
for ($i = 0; $i < 400; ++$i) {
    $at = (new DateTimeImmutable('2019-01-01 00:00:00', $zone))->modify('+' . $i . ' days'); $date = $at->format('Y-m-d');
    foreach (['SPY', 'QQQ', 'SVXY'] as $j => $symbol) { $price = 100 + $i / 5 + sin($i / (8 + $j)) * 8; $all[$symbol][] = new Bar($symbol, $at, $price, $price + 1, $price - 1, $price, 10000.); }
    $p = 50 + sin($i / 9) * 45; $breadth[$date] = ['open' => $p, 'high' => $p + 2, 'low' => $p - 2, 'close' => $p]; $vvix[$date] = 100 + sin($i / 11) * 20;
}
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php'; $exercised = 0;
foreach ($cases as $id => $case) {
    $base = S::maps($case['spec'], $all, $breadth, $vvix); $maps = B::maps($case, $all, $breadth, $vvix);
    $prefix = B::maps($case, array_map(static fn ($v): array => array_slice($v, 0, 300), $all), array_slice($breadth, 0, 300, true), array_slice($vvix, 0, 300, true));
    foreach (['scale', 'confirmation', 'bullish'] as $field) { $check(array_slice($maps[$field], 0, 300, true) === $prefix[$field], 'No future dependence: ' . $id); }
    $check($base['confirmation'] === $maps['confirmation'], 'Bull sizing never bypasses reentry/circuit confirmation.');
    $check(count($maps['scale']) === 400 && count($maps['bullish']) === 400, 'Complete dated maps.');
    foreach ($maps['scale'] as $date => $value) {
        $check(is_finite($value) && $value >= 0 && $value <= 2, 'Finite bounded exposure.');
        if (!$maps['bullish'][$date]) { $check($value === $base['scale'][$date], 'No boost outside completed bull confirmation.'); }
        else { ++$exercised; $check(abs($value - $base['scale'][$date] * $case['bull']['boost']) < 1e-12, 'Exactly declared boost.'); }
        if ($base['scale'][$date] <= .5) { $check(!$maps['bullish'][$date] && $value === $base['scale'][$date], 'SVXY defensive cap preserved.'); }
    }
    foreach ([30., 60.] as $cost) { $books = S::books($case['spec'], $profile, $maps['scale'], [], [], $cost); $check(count($books) === 12 && abs(array_sum(array_column($books, 'allocation')) - 1.) < 1e-12, 'Whole 12-sleeve capital unchanged.'); }
}
$check($exercised > 100, 'Bull condition actually exercised, not an always-false rule.');
$bad = $cases['bull_v100_ma50_boost105']; $bad['bull']['boost'] = 1.25;
try { B::maps($bad, $all, $breadth, $vvix); $check(false, 'Undeclared boost accepted.'); } catch (InvalidArgumentException) { $check(true, 'Undeclared boost rejected.'); }
$files = CandidateRelease::files(dirname(__DIR__));
$check(!isset($files['src/Research/CandidateBullRiskStudy.php']) && !isset($files['src/Research/CandidateResearchReplay.php']), 'Research stays outside active release.');
echo "Candidate bull-risk study: $n assertions PASS\n";
