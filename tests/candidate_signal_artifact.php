<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateSession as Session;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$reject = static function (callable $f, string $why) use ($check): void { try { $f(); } catch (Throwable) { $check(true, $why); return; } $check(false, $why); };
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php'; $base = require $root . '/config/tactical_rotation.php';
$books = Definition::books($base, [], [], []); $answers = [];
foreach ($books as $name => $book) {
    foreach (['', ...$book['config']['universe']] as $symbol) {
        $answers[$name][$symbol] = ['date' => '2026-09-11', 'previous_session' => '2026-09-10', 'reentry_conditions_met' => true,
            'desired' => ['MSFT' => .5], 'closes' => ['MSFT' => 100.], 'previous_closes' => ['MSFT' => 99.], 'volatility' => ['MSFT' => .03]];
    }
}
$inputs = ['date' => '2026-09-11', 'books' => $books, 'context_answers' => $answers, 'nominal_closes' => ['MSFT' => 100.], 'confirmation' => true, 'provenance' => ['fixture' => true]];
$hash = str_repeat('a', 64); $a = Artifact::build($inputs, $candidate, $hash, '2026-09-14'); $path = tempnam(sys_get_temp_dir(), 'candidate-signal-');
try {
    Artifact::write($path, $a); $roundtrip = Data::read($path);
    $parsed = Artifact::validate($roundtrip, $candidate, $hash, $base);
    $check($parsed['books'] === $a['books'], 'Recipe preserves integer/float distinctions across disk.');
    $check(count($parsed['contexts']) === 12, 'Twelve compact causal contexts.');
    $refreshSession = ['signal_date' => '2026-09-11', 'scheduled_session' => '2026-09-14'];
    $check(!Artifact::needsRefresh($path, $candidate, $hash, $base, $refreshSession), 'Valid compact artifact avoids unnecessary historical refresh.');
    $check(Artifact::needsRefresh($path . '-missing', $candidate, $hash, $base, $refreshSession), 'Missing artifact schedules refresh without stopping protection.');
    $check(Artifact::needsRefresh($path, $candidate, str_repeat('b', 64), $base, $refreshSession), 'Same-date runtime mismatch also schedules refresh.');
    $check(Artifact::needsRefresh($path, $candidate, $hash, $base, ['signal_date' => '2026-09-14', 'scheduled_session' => '2026-09-15']), 'Completed new date schedules refresh.');
    file_put_contents($path, '{broken');
    $check(Artifact::needsRefresh($path, $candidate, $hash, $base, $refreshSession), 'Malformed JSON cannot terminate the protective daemon.');
    $broken = $a; $broken['confirmation'] = false; Artifact::write($path, $broken);
    $check(Artifact::needsRefresh($path, $candidate, $hash, $base, $refreshSession), 'Same-date content corruption schedules refresh.');
    Artifact::write($path, $a);
    foreach ($parsed['contexts'] as $context) { $check($context('2026-09-11', null)['desired'] === ['MSFT' => .5], 'Frozen incumbent lookup.'); }
    $bad = $a; $bad['nominal_closes']['MSFT'] = 1.; $reject(fn () => Artifact::validate($bad, $candidate, $hash, $base), 'Price corruption rejected.');
    $reject(fn () => Artifact::validate($a, $candidate, str_repeat('b', 64), $base), 'Runtime drift rejected.');
    $reject(fn () => $parsed['contexts'][array_key_first($books)]('2026-09-14', null), 'Cannot relabel old data as current.');
    $bad = $a; $bad['books'][array_key_first($books)]['allocation'] = .9;
    $bad['content_sha256'] = hash('sha256', Order::json(array_diff_key($bad, ['generated_at' => true, 'content_sha256' => true])));
    $reject(fn () => Artifact::validate($bad, $candidate, $hash, $base), 'Valid checksum cannot authorize a different recipe.');
    $a['generated_at'] = '2026-09-12T00:00:00Z'; Artifact::validate($a, $candidate, $hash, $base); $check(true, 'Regeneration timestamp does not change a completed close.');
    $reject(fn () => Data::verifyProvenance($root, ['date' => '2026-09-11', 'raw_sha256' => str_repeat('0', 64)]), 'Original provider files must match, not only signal checksum.');
    $calendar = [['date' => '2026-09-10', 'open' => '09:30', 'close' => '16:00'], ['date' => '2026-09-11', 'open' => '09:30', 'close' => '13:00'],
        ['date' => '2026-09-14', 'open' => '09:30', 'close' => '16:00'], ['date' => '2026-09-15', 'open' => '09:30', 'close' => '16:00']];
    foreach ([['2026-09-11 13:19:59', false, '2026-09-10'], ['2026-09-11 13:20:00', false, '2026-09-11'],
        ['2026-09-13 12:00:00', false, '2026-09-11'], ['2026-09-14 09:30:00', true, '2026-09-11']] as [$at, $open, $expected]) {
        $now = new DateTimeImmutable($at, new DateTimeZone('America/New_York')); $clock = ['timestamp' => $now->format(DATE_ATOM), 'is_open' => $open];
        $check(Session::resolve($calendar, $clock, $now)['signal_date'] === $expected, 'Official holidays/early close and 20-minute finalization.');
        $reject(fn () => Session::resolve($calendar, array_replace($clock, ['is_open' => !$open]), $now), 'Clock/calendar disagreement rejected.');
        $reject(fn () => Session::resolve($calendar, $clock, $now->modify('+61 seconds')), 'Stale clock rejected.');
    }
    echo "candidate_signal_artifact: {$n} assertions PASS\n";
} finally { unlink($path); }
