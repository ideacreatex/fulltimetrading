#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\PaperCommentaryOutbox;
use FulltimeTrading\Notifications\PaperMarketCommentary;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSession;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $mode = $argv[1] ?? 'context';
if (!in_array($mode, ['context', 'preview', 'send'], true) || count($argv) !== ($mode === 'context' ? 2 : 3)) {
    throw new InvalidArgumentException('Usage: paper_market_commentary.php context | preview DRAFT.json | send DRAFT.json');
}
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$candidate = require $root . '/config/paper_candidate.php'; $config = Config::fromFile($root . '/config/config.php');
$release = CandidateRelease::verify($root, $candidate);
$directory = $root . '/var/reports/market_commentary';
if (!is_dir($directory . '/contexts')) { mkdir($directory . '/contexts', 0775, true); }
$outboxPath = $root . '/var/db/paper_market_commentary.sqlite';
if (realpath($outboxPath) !== false && realpath($outboxPath) === realpath((string) $config->get('database_path'))) { throw new RuntimeException('Commentary cannot use trading database.'); }
$outbox = new PaperCommentaryOutbox(new PDO('sqlite:' . $outboxPath));
$http = new HttpClient(); $client = new AlpacaPaperClient($http, getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'));
$account = $client->account(); AlpacaPaperAccountGuard::validateConfigured($account);
$now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$calendar = $client->calendar($now->modify('-15 days')->format('Y-m-d'), $now->modify('+15 days')->format('Y-m-d'));
$clock = $client->clock(); $due = PaperMarketCommentary::phase($calendar, $clock, $now);
if ($mode === 'context') {
    $dbPath = realpath((string) $config->get('database_path'));
    if ($dbPath === false) { throw new RuntimeException('Missing operational database.'); }
    $db = new PDO('sqlite:file:' . $dbPath . '?mode=ro'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA query_only=ON'); $db->exec('PRAGMA busy_timeout=5000'); $db->beginTransaction();
    $q = $db->prepare('SELECT * FROM tactical_paper_run WHERE run_id=?'); $q->execute([$candidate['run_id']]); $run = $q->fetch(PDO::FETCH_ASSOC);
    if (!$run || $run['runtime_hash'] !== $release['runtime_hash']) { throw new RuntimeException('Run identity mismatch.'); }
    $q = $db->prepare('SELECT scope,payload FROM tactical_candidate_checkpoint WHERE run_id=?'); $q->execute([$candidate['run_id']]); $checkpoints = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $checkpoints[$row['scope']] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
    $q = $db->prepare('SELECT sleeve_id,symbol,qty FROM tactical_paper_position WHERE run_id=?'); $q->execute([$candidate['run_id']]); $owned = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $owned[$row['sleeve_id']][$row['symbol']] = $row['qty']; }
    $q = $db->prepare('SELECT sleeve_id,symbol,side,requested_qty,cumulative_filled_qty,status,leg FROM tactical_paper_intent WHERE run_id=? ORDER BY created_at DESC LIMIT 50');
    $q->execute([$candidate['run_id']]); $intents = $q->fetchAll(PDO::FETCH_ASSOC); $db->commit();
    $positions = $client->positions(); $orders = $client->openOrders(); $close = $checkpoints['latest_close'] ?? null; $plans = [];
    foreach ($close['plans'] ?? [] as $name => $plan) {
        if ($plan['target_quantities'] === null) { continue; }
        $plans[$name] = ['action' => $plan['target']['action'], 'currently_owned' => $owned[$name] ?? [], 'target_quantities' => $plan['target_quantities'],
            'reference_nav' => $plan['reference_nav'], 'reference_prices' => $plan['reference_prices'], 'target_gross' => $plan['target']['gross'],
            'risk_exit_pending' => $plan['target']['risk_exit_pending'], 'circuit_feedback' => $plan['circuit_feedback']];
    }
    $context = ['schema' => 'paper-commentary-context-v1', 'captured_at' => gmdate(DATE_ATOM), 'paper_only' => true, 'orders_submitted' => 0,
        'operational_database_mode' => 'read_only', 'run_id' => $candidate['run_id'], 'runtime_hash' => $release['runtime_hash'], 'profile' => $candidate['profile'],
        'activation' => $run['activated_at'], 'initial_equity' => $run['initial_equity'], 'run_status' => $run['status'], 'due' => $due,
        'account' => array_intersect_key($account, array_flip(['equity', 'cash', 'buying_power', 'multiplier'])),
        'positions' => array_map(static fn ($p): array => array_intersect_key($p, array_flip(['symbol', 'qty', 'avg_entry_price', 'market_value', 'unrealized_pl', 'current_price'])), $positions),
        'open_orders' => array_map(static fn ($o): array => array_intersect_key($o, array_flip(['symbol', 'side', 'qty', 'filled_qty', 'status', 'type', 'time_in_force'])), $orders),
        'recent_intents' => $intents, 'signal_date' => $close['date'] ?? null, 'scheduled_session' => $close['scheduled_session'] ?? null, 'plans' => $plans,
        'market_data' => [], 'data_warnings' => [], 'limitations' => 'Sequential broker/ledger reads are not atomic; facts are timestamped observations, not execution commands.'];
    $session = CandidateSession::resolve($calendar, $clock, $now);
    $context['required_signal_date'] = $session['signal_date'];
    $context['signal_is_latest_completed_session'] = false;
    $verifiedArtifactDate = null;
    try {
        $artifact = CandidateDataSnapshot::read($root . '/var/reports/daily/candidate_signal.json');
        CandidateSignalArtifact::validate($artifact, $candidate, $release['runtime_hash'], require $root . '/config/tactical_rotation.php');
        CandidateDataSnapshot::verifyProvenance($root, $artifact['provenance']);
        $verifiedArtifactDate = $artifact['as_of'];
        $context['signal_is_latest_completed_session'] = $artifact['as_of'] === $session['signal_date'];
        $context['confirmation_for_circuit_reentry'] = $artifact['confirmation'];
        $suffix = str_replace('-', '', $artifact['as_of']); $rawPath = $root . '/var/reports/candidate_execution_data_' . $suffix . '/split.json'; $data = $read($rawPath);
        foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
            $series = $data[$symbol]; $last = end($series); $previous = $series[count($series) - 2]; $closes = array_column($series, 'c');
            $context['market_data'][$symbol] = ['as_of' => $artifact['as_of'], 'split_adjusted_close' => $last['c'], 'return_1d_pct' => 100 * ($last['c'] / $previous['c'] - 1),
                'sma50' => array_sum(array_slice($closes, -50)) / 50, 'sma200' => array_sum(array_slice($closes, -200)) / 200, 'source' => 'Alpaca SIP split-adjusted daily bars'];
        }
        unset($data);
        foreach (['s5tw', 'vvix'] as $name) {
            $series = $read($root . '/var/reports/candidate_external_data_' . $suffix . '/' . $name . '.json');
            $context['market_data'][$name] = ['as_of' => $artifact['as_of'], 'value' => $series[$artifact['as_of']], 'source' => $name === 'vvix' ? 'Cboe' : 'Investing.com breadth series'];
        }
    } catch (Throwable $e) { $context['data_warnings'][] = $e->getMessage(); }
    $context['data_warnings'] = array_merge($context['data_warnings'], PaperMarketCommentary::freshnessWarnings(
        $session['signal_date'], $verifiedArtifactDate, $context['signal_date']));
    if ($due !== null) { $context['existing_receipt'] = $outbox->receipt(PaperMarketCommentary::key($candidate['run_id'], $due['session_date'], $due['phase'])); }
    $relative = 'var/reports/market_commentary/contexts/' . gmdate('Ymd_His') . '_' . getmypid() . '.json'; Writer::write($root . '/' . $relative, $context);
    echo json_encode(['context_path' => $relative, 'context_sha256' => hash_file('sha256', $root . '/' . $relative), 'context' => $context], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
$draft = $read($argv[2]); $contextPath = $draft['context_path'] ?? '';
if (!is_string($contextPath) || !preg_match('~^var/reports/market_commentary/contexts/[0-9_]+\.json$~D', $contextPath)
    || !is_file($root . '/' . $contextPath) || !hash_equals(hash_file('sha256', $root . '/' . $contextPath), $draft['context_sha256'] ?? '')) {
    throw new RuntimeException('Invalid context path or hash.');
}
$context = $read($root . '/' . $contextPath);
if ($context['run_id'] !== $candidate['run_id'] || $context['runtime_hash'] !== $release['runtime_hash'] || $due !== $context['due'] || $due === null) {
    throw new RuntimeException('No current market-session commentary window.');
}
$message = PaperMarketCommentary::render($draft, $context, $now);
if ($mode === 'preview') { echo $message, "\n"; exit(0); }
$notifier = TelegramNotifier::fromEnv($http) ?? throw new RuntimeException('Telegram is not configured.');
$key = PaperMarketCommentary::key($candidate['run_id'], $due['session_date'], $due['phase']);
$receipt = $outbox->deliver($key, $message, static fn ($text): array => $notifier->sendMessage($text));
echo json_encode(['receipt' => $receipt, 'broker_orders_submitted' => 0, 'operational_database_modified' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit($receipt['status'] === 'delivered' ? 0 : 2);
