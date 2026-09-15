<?php
declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Research\DeployedCandidateStudy as Study;
use FulltimeTrading\Research\SelectedMaximumResearch as S;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$base = require dirname(__DIR__) . '/config/tactical_rotation.php'; $cases = Study::cases();
$check(count($cases) === 125, 'One deployed control and 124 hypotheses.');
$check(Study::books([], $base, [], [], [], 30.) === Definition::books($base, [], [], []), 'Exact deployed configuration parity.');
$check(!isset(Release::files(dirname(__DIR__))['src/Research/DeployedCandidateStudy.php']), 'Research is outside deployed identity.');
$check(S::hash($cases) === S::hash(json_decode(json_encode($cases, JSON_THROW_ON_ERROR), true)), 'Protocol checksum survives numeric JSON roundtrip.');
$all = $breadth = $vvix = []; $zone = new DateTimeZone('America/New_York');
for ($i = 0; $i < 350; ++$i) {
    $at = (new DateTimeImmutable('2020-01-02 00:00:00', $zone))->modify('+' . $i . ' days'); $date = $at->format('Y-m-d');
    foreach (['SPY', 'QQQ', 'SVXY'] as $j => $symbol) {
        $v = 100 + $i / 10 + sin($i / (7 + $j)) * 10;
        $all[$symbol][] = new Bar($symbol, $at, $v, $v + 1, $v - 1, $v, 100000.);
    }
    $v = 50 + sin($i / 8) * 45; $breadth[$date] = ['open' => $v, 'high' => min(100., $v + 2), 'low' => max(0., $v - 2), 'close' => $v];
    $vvix[$date] = 100 + cos($i / 11) * 25;
}
$short = array_map(static fn ($series): array => array_slice($series, 0, 280), $all);
$breadthShort = array_slice($breadth, 0, 280, true); $vvixShort = array_slice($vvix, 0, 280, true);
$signatures = [];
foreach ($cases as $id => $spec) {
    $sig = S::hash(array_diff_key($spec, ['family' => true]));
    $check(!isset($signatures[$sig]), 'Distinct declared recipe ' . $id); $signatures[$sig] = true;
    foreach ([30., 60.] as $cost) {
        $books = Study::books($spec, $base, [], [], [], $cost);
        $check(abs(array_sum(array_column($books, 'allocation')) - 1.) < 1e-12, 'No invented capital in ' . $id);
    }
    $maps = Study::maps($spec, $all, $breadth, $vvix); $prefix = Study::maps($spec, $short, $breadthShort, $vvixShort);
    $check(count($maps['scale']) === 350 && count($maps['confirmation']) === 350, 'Complete causal maps ' . $id);
    $check(array_slice($maps['scale'], 0, 280, true) === $prefix['scale']
        && array_slice($maps['confirmation'], 0, 280, true) === $prefix['confirmation'], 'No future dependence in ' . $id);
    $check(array_filter($maps['scale'], static fn ($v): bool => !is_finite($v) || $v < 0 || $v > 2) === [], 'Finite bounded sizing map ' . $id);
}
echo "deployed_candidate_study: {$n} assertions PASS\n";
