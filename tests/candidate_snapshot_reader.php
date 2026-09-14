<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateSnapshotReader as Reader;
use FulltimeTrading\Trading\TacticalPaperObservation as Observation;
use FulltimeTrading\Trading\TacticalPortfolioWeeklySummary as Weekly;

require dirname(__DIR__) . '/bootstrap.php';
ini_set('memory_limit', '128M');
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void {
    ++$n; if (!$ok) { throw new RuntimeException($why); }
};
$path = tempnam(sys_get_temp_dir(), 'candidate-snapshots-');
try {
    $db = new PDO('sqlite:' . $path); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE tactical_paper_snapshot (id INTEGER PRIMARY KEY,run_id TEXT,captured_at TEXT,equity REAL,reconciliation_status TEXT,payload TEXT)');
    $q = $db->prepare('INSERT INTO tactical_paper_snapshot (run_id,captured_at,equity,reconciliation_status,payload) VALUES (?,?,?,?,?)');
    $insert = static function (string $run, string $at, float $equity, string $status = 'ok', array $payload = []) use ($q, $db): array {
        $q->execute([$run, $at, $equity, $status, json_encode($payload, JSON_THROW_ON_ERROR)]);
        return ['id' => (int) $db->lastInsertId(), 'captured_at' => $at, 'equity' => $equity, 'reconciliation_status' => $status, 'payload' => $payload];
    };
    $rows = [];
    // Neither daily high nor daily low belongs to the exact worst drawdown pair.
    foreach ([50., 110., 100., 90., 120.] as $i => $equity) {
        $rows[] = $insert('pair', sprintf('2026-09-14T14:0%d:00+00:00', $i), $equity, 'ok', ['dry_run' => $i !== 2]);
    }
    $since = '2026-09-14T14:00:00Z'; $now = new DateTimeImmutable('2026-09-15T00:00:00Z');
    $view = Reader::read($path, 'pair', $since, $now, 50.);
    $check(abs(Observation::maxDrawdown($view['snapshots'], 50.) - (90 / 110 - 1)) < 1e-12, 'Non-extreme peak and trough retained.');
    $check(Observation::dates($view['snapshots']) === Observation::dates($rows), 'A real observation among dry-run extrema retains the market date.');
    $check($view['total_snapshots'] === 5 && $view['error_snapshots'] === 0, 'Exact uncompressed counts.');
    $check(Weekly::fromSnapshots($view['snapshots'], '2026-09-14', 125.) === Weekly::fromSnapshots($rows, '2026-09-14', 125.), 'Weekly endpoints and extremes preserved.');
    $rows = []; $ny = new DateTimeZone('America/New_York');
    $start = new DateTimeImmutable('2026-08-24T13:00:00Z');
    for ($i = 0; $i < 800; ++$i) {
        $at = $start->modify('+' . ($i * 3600) . ' seconds');
        $rows[] = $insert('mixed', ($i % 2 ? $at->setTimezone($ny) : $at)->format(DATE_ATOM),
            round(100. + sin($i / 17) * 18 + $i / 40, 6), $i % 17 === 0 ? 'paused_test' : 'ok',
            ['errors' => $i % 19 === 0 ? ['test'] : [], 'dry_run' => $i % 9 === 0]);
    }
    $since = '2026-08-25T15:00:00+03:00'; $now = new DateTimeImmutable('2026-09-24T21:00:00Z');
    $full = Observation::since($rows, $since, $now); $view = Reader::read($path, 'mixed', $since, $now, 100.);
    $check($view['total_snapshots'] === count($full), 'Mixed offsets and observation bounds preserve exact count.');
    $expectedErrors = count(array_filter($full, static fn ($r): bool => str_starts_with($r['reconciliation_status'], 'paused_') || $r['payload']['errors'] !== []));
    $check($view['error_snapshots'] === $expectedErrors, 'Error-rate numerator uses every observation, not representatives.');
    $check(Observation::dates($view['snapshots']) === Observation::dates($full), 'All stored/market dates preserved, including weekends and holidays.');
    $check(Observation::maxDrawdown($view['snapshots'], 100.) === Observation::maxDrawdown($full, 100.), 'Full drawdown parity across days.');
    foreach (['2026-08-28', '2026-09-04', '2026-09-11', '2026-09-18', '2026-09-24'] as $session) {
        $check(Weekly::fromSnapshots($view['snapshots'], $session, 115.) === Weekly::fromSnapshots($full, $session, 115.), 'Weekly summary parity ' . $session);
    }
    foreach ([0., -10.] as $equity) {
        $insert('insolvent', '2026-09-14T15:00:00Z', $equity);
    }
    $view = Reader::read($path, 'insolvent', '2026-09-14T00:00:00Z', $now, 100.);
    $check(abs($view['max_drawdown'] - -1.1) < 1e-12, 'Zero/negative equity must remain reportable, not disappear from risk evidence.');
    unset($rows, $full, $view);
    $db->beginTransaction(); $peak = 100.; $worst = 0.; $errors = 0;
    for ($i = 0; $i < 100000; ++$i) {
        $equity = round(100. + sin($i / 37) * 30 + $i / 100000, 6);
        $at = $start->modify('+' . ($i * 15) . ' seconds');
        $error = $i % 997 === 0; $errors += (int) $error;
        $insert('large', $at->format(DATE_ATOM), $equity, $error ? 'blocked_test' : 'ok', ['unused_detail' => str_repeat('x', 1024)]);
        $peak = max($peak, $equity); $worst = min($worst, $equity / $peak - 1);
    }
    $db->commit();
    $view = Reader::read($path, 'large', $start->format(DATE_ATOM), $now, 100.);
    $check($view['total_snapshots'] === 100000 && $view['error_snapshots'] === $errors, '100,000 observations counted exactly.');
    $check($view['max_drawdown'] === $worst && Observation::maxDrawdown($view['snapshots'], 100.) === $worst, '100,000-observation risk parity.');
    $check(count($view['snapshots']) <= 5 * 19 + 2, 'Bounded representatives per day plus exact worst pair.');
    $check(memory_get_peak_usage(true) < 128 * 1024 * 1024, 'Bounded report memory under 128 MiB.');
    echo "candidate_snapshot_reader: {$n} assertions; 100000 rows; " . memory_get_peak_usage(true) . " peak bytes PASS\n";
} finally {
    unset($q, $insert, $db);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
