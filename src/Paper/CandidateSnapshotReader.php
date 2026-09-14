<?php
declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Bounded reporting view: preserve day/week endpoints, extremes and the exact worst drawdown pair. */
final class CandidateSnapshotReader
{
    public static function read(string $database, string $run, string $since, \DateTimeImmutable $now, float $initial): array
    {
        $db = new \PDO('sqlite:file:' . $database . '?mode=ro');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); $db->exec('PRAGMA busy_timeout=5000');
        $q = $db->prepare('SELECT id,captured_at,equity,reconciliation_status,payload FROM tactical_paper_snapshot
            WHERE run_id=? ORDER BY julianday(captured_at),id'); $q->execute([$run]);
        $start = new \DateTimeImmutable($since); $zone = new \DateTimeZone('America/New_York');
        $days = []; $count = $errors = 0; $peak = $initial; $peakRow = null; $worst = 0.; $pair = [];
        while ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
            $at = new \DateTimeImmutable($row['captured_at']);
            if ($at < $start || $at > $now) { continue; }
            $equity = (float) $row['equity'];
            if (!is_finite($equity)) { throw new \RuntimeException('Invalid candidate equity observation.'); }
            $row['equity'] = $equity; $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            ++$count;
            $payloadErrors = is_array($row['payload']['errors'] ?? null)
                ? array_values(array_filter($row['payload']['errors'], static fn ($e): bool => trim((string) $e) !== '')) : [];
            $errors += (int) (str_starts_with($row['reconciliation_status'], 'blocked_') || str_starts_with($row['reconciliation_status'], 'paused_') || $payloadErrors !== []);
            $row['payload'] = ['errors' => $payloadErrors, 'dry_run' => ($row['payload']['dry_run'] ?? false) === true];
            $day = $at->setTimezone($zone)->format('Y-m-d');
            if (!isset($days[$day])) { $days[$day] = ['first' => $row, 'last' => $row, 'high' => $row, 'low' => $row]; }
            if (!$row['payload']['dry_run'] && !isset($days[$day]['real'])) { $days[$day]['real'] = $row; }
            $days[$day]['last'] = $row;
            if ($equity > $days[$day]['high']['equity']) { $days[$day]['high'] = $row; }
            if ($equity < $days[$day]['low']['equity']) { $days[$day]['low'] = $row; }
            if ($equity > $peak) { $peak = $equity; $peakRow = $row; }
            $dd = $peak > 0 ? $equity / $peak - 1 : 0.;
            if ($dd < $worst) { $worst = $dd; $pair = array_values(array_filter([$peakRow, $row])); }
        }
        $selected = [];
        foreach ($days as $day) { foreach ($day as $row) { $selected[$row['id']] = $row; } }
        foreach ($pair as $row) { $selected[$row['id']] = $row; }
        $rows = array_values($selected);
        usort($rows, static fn ($a, $b): int => (new \DateTimeImmutable($a['captured_at']) <=> new \DateTimeImmutable($b['captured_at'])) ?: ($a['id'] <=> $b['id']));
        return ['snapshots' => $rows, 'total_snapshots' => $count, 'error_snapshots' => $errors, 'max_drawdown' => $worst];
    }
}
