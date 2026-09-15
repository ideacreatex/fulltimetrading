#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\PaperForwardExecutionAudit;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php';
$config = Config::fromFile($root . '/config/config.php'); $report = ['generated_at' => gmdate(DATE_ATOM),
    'run_id' => $candidate['run_id'], 'paper_only' => true, 'orders_submitted' => 0, 'database_mode' => 'read_only'];
try {
    $release = CandidateRelease::verify($root, $candidate);
    $client = new AlpacaPaperClient(new HttpClient(), getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'));
    $dbPath = realpath((string) $config->get('database_path'));
    if ($dbPath === false) { throw new RuntimeException('Operational database missing.'); }
    $db = new PDO('sqlite:file:' . $dbPath . '?mode=ro'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA query_only=ON'); $db->exec('PRAGMA busy_timeout=5000');
    $read = static function () use ($db, $candidate): array {
        $s = []; $db->beginTransaction();
        try {
            foreach (['run' => 'tactical_paper_run', 'intents' => 'tactical_paper_intent', 'owned_positions' => 'tactical_paper_position',
                'sleeves' => 'tactical_paper_sleeve', 'checkpoints' => 'tactical_candidate_checkpoint'] as $key => $table) {
                $q = $db->prepare('SELECT * FROM ' . $table . ' WHERE run_id = ? ORDER BY rowid'); $q->execute([$candidate['run_id']]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$row) {
                    unset($row['updated_at']);
                    if (isset($row['payload'])) { $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
                }
                unset($row); $s[$key] = $rows;
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
        $checkpoints = []; foreach ($s['checkpoints'] as $row) { $checkpoints[$row['scope']] = $row['payload']; }
        $s['checkpoints'] = $checkpoints; return $s;
    };
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $before = $read(); $s = $before;
        if (count($s['run']) !== 1 || $s['run'][0]['runtime_hash'] !== $release['runtime_hash']) { throw new RuntimeException('Run identity mismatch.'); }
        $report['run_status'] = $s['run'][0]['status']; $report['activated_at'] = $s['run'][0]['activated_at'];
        $s['account'] = $client->account(); $report['account_guard'] = AlpacaPaperAccountGuard::validateConfigured($s['account']);
        $s['positions'] = $client->positions(); $s['open_orders'] = $client->openOrders(); $s['broker_order_observations'] = [];
        $latest = array_slice(array_reverse($s['intents']), 0, 50); $lookup = [];
        foreach ([...$latest, ...$s['intents']] as $i) {
            if ((int) $i['attempt_count'] !== 1 || (isset($lookup[$i['client_order_id']]))) { continue; }
            if (!in_array($i, $latest, true) && in_array($i['status'], CandidateOrder::TERMINAL, true)) { continue; }
            $lookup[$i['client_order_id']] = true;
            $s['broker_order_observations'][$i['client_order_id']] = $client->orderByClientOrderId($i['client_order_id']);
        }
        $s['observation_complete'] = true; $s['ledger_stable'] = $before === $read();
        $audit = PaperForwardExecutionAudit::inspect($s); $report['attempts'] = $attempt;
        if ($audit['ok'] || $attempt === 3) { break; }
        sleep(3);
    }
    $report['audit'] = $audit; $report['runtime_hash'] = $release['runtime_hash'];
    $report['broker'] = array_intersect_key($s['account'], array_flip(['equity', 'cash', 'buying_power']));
    $report['observation'] = ['positions' => $s['positions'], 'open_orders' => $s['open_orders'], 'order_lookups' => $s['broker_order_observations']];
    $report['clock'] = $client->clock(); $report['completed_at'] = gmdate(DATE_ATOM);
    $report['historical_order_lookup_scope'] = 'Latest 50 ledger orders plus every active intent; older fills are checked by cash/position conservation, not re-fetched.';
} catch (Throwable $e) {
    $report['audit'] = ['ok' => false, 'status' => 'unconfirmed', 'errors' => [get_class($e) . ': ' . $e->getMessage()]];
}
$out = $root . '/var/reports/candidate_forward_20260915';
if (!is_dir($out)) { mkdir($out, 0775, true); }
AlgorithmTrendResearch::write($out . '/' . gmdate('Ymd_His') . '_' . getmypid() . '.json', $report);
AlgorithmTrendResearch::write($out . '/latest.json', $report);
echo json_encode(array_diff_key($report, ['observation' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($report['audit']['ok'] ? 0 : 2);
