<?php
declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\CandidateInteractionStudy as I;
use FulltimeTrading\Research\DeployedCandidateStudy as S;
use FulltimeTrading\Research\SelectedMaximumResearch as H;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$cases = I::cases(); $check(count($cases) === 24, 'Exactly 24 predeclared factorial cells.');
$reuse = $new = 0; $signatures = []; $all = $breadth = $vvix = [];
for ($i = 0; $i < 350; ++$i) {
    $time = (new DateTimeImmutable('2019-01-01 00:00:00', new DateTimeZone('America/New_York')))->modify('+' . $i . ' days'); $date = $time->format('Y-m-d');
    foreach (['SPY', 'QQQ', 'SVXY'] as $j => $symbol) {
        $p = 100 + sin($i / (7 + $j)) * 20 + $i / 10; $all[$symbol][] = new Bar($symbol, $time, $p, $p + 1, $p - 1, $p, 100000.);
    }
    $p = 50 + sin($i / 8) * 44; $breadth[$date] = ['open' => $p, 'high' => $p + 2, 'low' => $p - 2, 'close' => $p]; $vvix[$date] = 100 + sin($i / 13) * 20;
}
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
foreach ($cases as $id => $row) {
    $signature = H::hash($row['factors']); $check(!isset($signatures[$signature]), 'Unique factorial cell.'); $signatures[$signature] = true;
    $full = S::maps($row['spec'], $all, $breadth, $vvix);
    $prefix = S::maps($row['spec'], array_map(static fn ($v): array => array_slice($v, 0, 280), $all), array_slice($breadth, 0, 280, true), array_slice($vvix, 0, 280, true));
    foreach (['scale', 'confirmation'] as $field) { $check(array_slice($full[$field], 0, 280, true) === $prefix[$field], 'Future observations cannot change earlier decisions.'); }
    $check(array_filter($full['scale'], static fn ($v): bool => !is_finite($v) || $v < 0 || $v > 2) === [], 'Finite bounded risk.');
    foreach ([30., 60.] as $cost) {
        $books = S::books($row['spec'], $profile, $full['scale'], [], [], $cost);
        $check(count($books) === 12 && abs(array_sum(array_column($books, 'allocation')) - 1.) < 1e-12, 'No invented capital/sleeves.');
        foreach (I::conditions() as $condition => $_) { I::priorCase($id, $condition, (int) $cost) === null ? ++$new : ++$reuse; }
    }
    $neighbors = I::neighbors($id); $check(count($neighbors) >= 4 && count($neighbors) <= 5, 'Complete adjacent factor neighborhood.');
    foreach ($neighbors as $neighbor => $factor) { $check((I::neighbors($neighbor)[$id] ?? null) === $factor, 'Symmetric neighborhood.'); }
}
$check($reuse === 36 && $new === 156, '36 prior results reused, 156 genuinely new replays.');
$check(!isset(CandidateRelease::files(dirname(__DIR__))['src/Research/CandidateInteractionStudy.php']), 'No active release edits.');
$check(I::cases()['svxy_cap_0.75']['spec'] === S::cases()['svxy_cap_0.75'], 'Reused single-factor recipe is exact.');
echo "Candidate interaction study: $n assertions PASS\n";
