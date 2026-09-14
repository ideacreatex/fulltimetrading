<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Storage\TacticalPaperRepository;
use PDO;

/** Uses the audited tactical tables, without relaxing the old four-sleeve/order contract. */
final class CandidateLedger
{
    private PDO $db;
    public readonly TacticalPaperRepository $views;

    public function __construct(string $path)
    {
        $this->views = new TacticalPaperRepository($path);
        $this->views->migrate();
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS tactical_candidate_checkpoint (
    run_id TEXT NOT NULL, scope TEXT NOT NULL, version INTEGER NOT NULL,
    payload TEXT NOT NULL, updated_at TEXT NOT NULL,
    PRIMARY KEY(run_id,scope), FOREIGN KEY(run_id) REFERENCES tactical_paper_run(run_id)
);
CREATE TABLE IF NOT EXISTS tactical_candidate_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL, event_key TEXT NOT NULL,
    kind TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE(run_id,event_key), FOREIGN KEY(run_id) REFERENCES tactical_paper_run(run_id)
);
SQL);
    }

    public function provision(array $identity, array $allocations): array
    {
        if (($identity['data_contract']['execution_contract'] ?? null) !== CandidateOrder::CONTRACT
            || ($identity['data_contract']['paper_only'] ?? null) !== true
            || count($allocations) !== 12 || abs(array_sum($allocations) - 1) > 1.0e-12) {
            throw new \InvalidArgumentException('Candidate requires a versioned paper-only twelve-sleeve contract.');
        }
        foreach (['strategy_hash', 'runtime_hash'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $identity[$field] ?? '')) { throw new \InvalidArgumentException('Invalid release hash.'); }
        }
        foreach (['run_id', 'profile'] as $field) {
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $identity[$field] ?? '')) { throw new \InvalidArgumentException('Invalid release identity.'); }
        }
        foreach ($allocations as $id => $allocation) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $id)
                || !is_numeric($allocation) || !is_finite((float) $allocation) || $allocation <= 0 || $allocation >= 1) {
                throw new \InvalidArgumentException('Invalid candidate capital allocation.');
            }
        }
        return $this->transaction(function () use ($identity, $allocations): array {
            $run = $this->run($identity['run_id']);
            if ($run !== null) {
                foreach (['profile', 'strategy_hash', 'runtime_hash', 'data_contract'] as $field) {
                    $expected = $field === 'data_contract' ? CandidateOrder::json($identity[$field]) : $identity[$field];
                    if (!hash_equals($run[$field], $expected)) { throw new \RuntimeException('Candidate identity drift: ' . $field); }
                }
                $this->views->assertSleeveDefinitions($identity['run_id'], $allocations);
                if (!empty($run['activated_at'])) {
                    $boundary = (new \DateTimeImmutable($run['activated_at']))->modify('+31 days');
                    if (new \DateTimeImmutable($run['live_review_not_before']) < $boundary) {
                        $this->execute('UPDATE tactical_paper_run SET live_review_not_before=? WHERE run_id=?', [$boundary->format(DATE_ATOM), $identity['run_id']]);
                        $run = $this->run($identity['run_id']);
                    }
                }
                return $run;
            }
            $this->execute('INSERT INTO tactical_paper_run(run_id,profile,strategy_hash,runtime_hash,data_contract,status,live_review_not_before,created_at,updated_at)
                VALUES(?,?,?,?,?,\'transition\',?,?,?)', [$identity['run_id'], $identity['profile'], $identity['strategy_hash'],
                $identity['runtime_hash'], CandidateOrder::json($identity['data_contract']), self::now(), self::now(), self::now()]);
            foreach ($allocations as $id => $allocation) {
                $this->execute('INSERT INTO tactical_paper_sleeve(run_id,sleeve_id,allocation,updated_at) VALUES(?,?,?,?)',
                    [$identity['run_id'], $id, $allocation, self::now()]);
            }
            $this->event($identity['run_id'], 'provision', 'provision', $identity + ['allocations' => $allocations]);
            return $this->run($identity['run_id']);
        });
    }

    /** The caller must obtain the broker snapshot under the existing account mutation lease. */
    public function activate(string $runId, float $equity, array $flatSnapshot): void
    {
        $this->assertRun($runId);
        // The existing flat-handoff checks and static-capital allocation are shared, not bypassed.
        $this->views->activate($runId, $equity, $flatSnapshot);
        $run = $this->views->run($runId);
        $notBefore = (new \DateTimeImmutable($run['activated_at']))->modify('+31 days')->format(DATE_ATOM);
        $this->transaction(function () use ($runId, $notBefore): void {
            $this->execute('UPDATE tactical_paper_run SET live_review_not_before=? WHERE run_id=?', [$notBefore, $runId]);
            $this->event($runId, 'activation', 'activation', ['live_review_not_before' => $notBefore]);
        });
    }

    public function create(array $intent): array
    {
        CandidateOrder::assert($intent);
        return $this->transaction(function () use ($intent): array {
            $run = $this->assertRun($intent['run_id']);
            $existing = $this->decodeIntent($this->row('SELECT * FROM tactical_paper_intent WHERE epoch_key=?', [$intent['epoch_key']]));
            if ($existing !== null) {
                CandidateOrder::assert($existing);
                if (!hash_equals($existing['decision_id'], $intent['decision_id'])) { throw new \RuntimeException('Immutable candidate epoch drift.'); }
                return $existing;
            }
            if ($run['status'] !== 'active' && !($run['status'] === 'paused' && $intent['side'] === 'sell')) {
                throw new \RuntimeException('Candidate run does not permit this intent.');
            }
            if ($intent['side'] === 'buy') {
                foreach ($this->active($intent['run_id']) as $open) {
                    if (in_array($open['status'], ['submitting', 'ambiguous', 'pending_cancel'], true)
                        || isset($open['payload']['cancel_request'])
                        || ($open['sleeve_id'] === $intent['sleeve_id'] && $open['leg'] !== 'protective_stop')) {
                        throw new \RuntimeException('Unresolved candidate execution blocks new risk.');
                    }
                }
                $latch = $this->checkpoint($intent['run_id'], 'stop_latch:' . $intent['sleeve_id']);
                if (($latch['payload']['pending'] ?? false) === true) { throw new \RuntimeException('Unconsumed stop event blocks new risk.'); }
                foreach ($this->execute('SELECT symbol,qty FROM tactical_paper_position WHERE run_id=? AND sleeve_id=? AND qty>0',
                    [$intent['run_id'], $intent['sleeve_id']])->fetchAll() as $position) {
                    if ($position['symbol'] !== $intent['symbol']) { throw new \RuntimeException('Replacement entry requires confirmed prior exit.'); }
                }
            }
            if ($intent['side'] === 'sell') { $this->assertSellCapacity($intent); }
            $now = self::now();
            $this->execute('INSERT INTO tactical_paper_intent(decision_id,epoch_key,run_id,sleeve_id,signal_date,scheduled_session,
                leg,symbol,side,requested_qty,client_order_id,status,payload,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,\'planned\',?,?,?)',
                [$intent['decision_id'], $intent['epoch_key'], $intent['run_id'], $intent['sleeve_id'], $intent['signal_date'],
                    $intent['scheduled_session'], $intent['leg'], $intent['symbol'], $intent['side'], $intent['requested_qty'],
                    $intent['client_order_id'], CandidateOrder::json($intent['payload']), $now, $now]);
            $this->event($intent['run_id'], 'intent:' . $intent['decision_id'], 'intent', $intent);
            return $this->intent($intent['decision_id']);
        });
    }

    /** At most one POST claim. Ambiguous submissions are lookup-only, including after a restart. */
    public function claim(string $id): bool
    {
        return $this->transaction(function () use ($id): bool {
            $i = $this->requireIntent($id); $run = $this->assertRun($i['run_id']);
            if ($i['status'] !== 'planned' || (int) $i['attempt_count'] !== 0) { return false; }
            if ($run['status'] !== 'active' && !($run['status'] === 'paused' && $i['side'] === 'sell')) { return false; }
            if ($i['side'] === 'sell') { $this->assertSellCapacity($i, $id); }
            $this->execute('UPDATE tactical_paper_intent SET status=\'submitting\',attempt_count=1,updated_at=? WHERE decision_id=?', [self::now(), $id]);
            return true;
        });
    }

    public function ambiguous(string $id): void
    {
        $this->transaction(function () use ($id): void {
            $i = $this->requireIntent($id);
            if ($i['status'] === 'submitting') {
                $this->execute('UPDATE tactical_paper_intent SET status=\'ambiguous\',updated_at=? WHERE decision_id=?', [self::now(), $id]);
                $this->event($i['run_id'], 'ambiguous:' . $id, 'ambiguous_submission', ['decision_id' => $id]);
            }
        });
    }

    /** Persist cancel intent before DELETE. A cancellation response is not a terminal fill/cancel observation. */
    public function requestCancel(string $id, string $reason, bool $freshOpenObservation = false): bool
    {
        if (!in_array($reason, ['close_stop_update', 'rebalance', 'circuit', 'entry_window_expired', 'partial_fill_protection', 'entry_batch'], true)) {
            throw new \InvalidArgumentException('Unrecognized cancellation reason.');
        }
        return $this->transaction(function () use ($id, $reason, $freshOpenObservation): bool {
            $i = $this->requireIntent($id);
            if (in_array($i['status'], CandidateOrder::TERMINAL, true)) { return false; }
            $payload = $i['payload'];
            if (isset($payload['cancel_request'])) {
                $request = $payload['cancel_request']; $attempt = (int) ($request['attempts'] ?? 1);
                if (!$freshOpenObservation || $i['status'] === 'pending_cancel' || $attempt >= 3
                    || time() - strtotime($request['last_attempt_at'] ?? $request['at']) < 30) { return false; }
                $payload['cancel_request']['attempts'] = ++$attempt;
                $payload['cancel_request']['last_attempt_at'] = self::now();
                $this->execute('UPDATE tactical_paper_intent SET payload=?,updated_at=? WHERE decision_id=?', [CandidateOrder::json($payload), self::now(), $id]);
                $this->event($i['run_id'], 'cancel:' . $id . ':' . $attempt, 'cancel_retried_after_open_observation', ['decision_id' => $id]);
                return true;
            }
            if ($i['status'] === 'planned' && (int) $i['attempt_count'] === 0) {
                $this->execute('UPDATE tactical_paper_intent SET status=\'canceled\',updated_at=? WHERE decision_id=?', [self::now(), $id]);
                return false;
            }
            if (trim((string) $i['order_id']) === '') { throw new \RuntimeException('Cannot cancel an unresolved broker submission.'); }
            $payload['cancel_request'] = ['reason' => $reason, 'at' => self::now(), 'last_attempt_at' => self::now(), 'attempts' => 1];
            $this->execute('UPDATE tactical_paper_intent SET payload=?,updated_at=? WHERE decision_id=?',
                [CandidateOrder::json($payload), self::now(), $id]);
            $this->event($i['run_id'], 'cancel:' . $id, 'cancel_requested', ['decision_id' => $id, 'reason' => $reason]);
            return true;
        });
    }

    /** Atomic cumulative-fill delta, cash, cost basis and append-only audit. */
    public function observe(string $id, array $order): array
    {
        return $this->transaction(function () use ($id, $order): array {
            $i = $this->requireIntent($id); $this->assertRun($i['run_id']);
            if ((int) $i['attempt_count'] !== 1) { throw new \RuntimeException('Broker order observed without a persisted submission claim.'); }
            $body = $i['payload']['body'];
            if (!in_array($order['order_class'] ?? 'simple', ['simple', ''], true) || !empty($order['legs']) || isset($order['notional'])) {
                throw new \RuntimeException('Unexpected complex or notional candidate broker order.');
            }
            foreach (['client_order_id', 'symbol', 'side', 'type', 'time_in_force', 'extended_hours'] as $field) {
                if (($order[$field] ?? null) !== $body[$field]) { throw new \RuntimeException('Candidate broker body drift: ' . $field); }
            }
            if (CandidateOrder::quantity($order['qty'] ?? null) !== CandidateOrder::quantity($i['requested_qty'])) {
                throw new \RuntimeException('Candidate broker quantity drift.');
            }
            if (isset($body['stop_price']) && (!is_numeric($order['stop_price'] ?? null)
                || abs((float) $order['stop_price'] - (float) $body['stop_price']) > 1.0e-8)) {
                throw new \RuntimeException('Candidate broker stop price drift.');
            }
            $orderId = $order['id'] ?? null;
            if (!is_string($orderId) || trim($orderId) === '' || ($i['order_id'] !== null && $i['order_id'] !== $orderId)) {
                throw new \RuntimeException('Candidate broker order id drift.');
            }
            $status = $order['status'] ?? null;
            if ($status === 'cancelled') { $status = 'canceled'; }
            if (!in_array($status, ['pending_new', 'accepted', 'accepted_for_bidding', 'new', 'partially_filled',
                'pending_cancel', 'done_for_day', ...CandidateOrder::TERMINAL], true)) {
                throw new \RuntimeException('Unsupported candidate broker status.');
            }
            if (in_array($i['status'], CandidateOrder::TERMINAL, true) && $i['status'] !== $status) {
                throw new \RuntimeException('Terminal candidate order regression.');
            }
            $qty = CandidateOrder::quantity($order['filled_qty'] ?? null, true);
            $oldQty = CandidateOrder::quantity($i['cumulative_filled_qty'], true);
            if ($qty < $oldQty || $qty > (int) $i['requested_qty'] || ($status === 'filled' && $qty !== (int) $i['requested_qty'])) {
                throw new \RuntimeException('Non-monotonic or invalid candidate cumulative fill.');
            }
            $price = $qty === 0 ? 0.0 : ($order['filled_avg_price'] ?? null);
            if (!is_numeric($price) || !is_finite((float) $price) || ($qty > 0 && $price <= 0)) {
                throw new \RuntimeException('Invalid candidate cumulative fill price.');
            }
            $notional = $qty * (float) $price; $deltaQty = $qty - $oldQty;
            $deltaNotional = $notional - (float) $i['cumulative_fill_notional'];
            if (($deltaQty === 0 && abs($deltaNotional) > 0.000001) || ($deltaQty > 0 && $deltaNotional <= 0)) {
                throw new \RuntimeException('Candidate fill price correction requires activity reconciliation.');
            }
            if ($deltaQty > 0) {
                $this->fill($i, $deltaQty, $deltaNotional);
                $this->execute('INSERT INTO tactical_paper_fill_audit(fill_key,decision_id,order_id,cumulative_qty,cumulative_notional,recorded_at,payload)
                    VALUES(?,?,?,?,?,?,?)', [hash('sha256', $id . ':' . $qty), $id, $orderId, $qty, $notional, self::now(),
                        CandidateOrder::json(['delta_qty' => $deltaQty, 'delta_notional' => $deltaNotional, 'status' => $status])]);
            }
            $this->execute('UPDATE tactical_paper_intent SET order_id=?,status=?,cumulative_filled_qty=?,cumulative_fill_notional=?,
                submitted_at=COALESCE(submitted_at,?),updated_at=? WHERE decision_id=?',
                [$orderId, $status, $qty, $notional, self::now(), self::now(), $id]);
            $expectedStopCancel = $i['leg'] === 'protective_stop' && $status === 'canceled' && isset($i['payload']['cancel_request']);
            $expectedEntryCancel = $i['side'] === 'buy' && $status === 'canceled'
                && in_array($i['payload']['cancel_request']['reason'] ?? '', ['partial_fill_protection', 'entry_window_expired'], true);
            if (in_array($status, CandidateOrder::TERMINAL, true) && $qty < (int) $i['requested_qty'] && !$expectedStopCancel && !$expectedEntryCancel) {
                $this->execute('UPDATE tactical_paper_run SET status=\'paused\',last_error_code=?,updated_at=? WHERE run_id=?',
                    ['candidate_terminal_incomplete:' . substr($id, 0, 12), self::now(), $i['run_id']]);
            }
            return $this->intent($id);
        });
    }

    public function active(string $runId, ?string $sleeve = null): array
    {
        $this->assertRun($runId);
        $rows = $this->execute('SELECT * FROM tactical_paper_intent WHERE run_id=?
            AND status NOT IN (\'filled\',\'canceled\',\'expired\',\'rejected\') ORDER BY created_at,decision_id', [$runId])->fetchAll();
        return array_values(array_filter(array_map($this->decodeIntent(...), $rows),
            static fn ($i): bool => $sleeve === null || $i['sleeve_id'] === $sleeve));
    }

    public function run(string $runId): ?array
    {
        return $this->row('SELECT * FROM tactical_paper_run WHERE run_id=?', [$runId]);
    }

    public function intent(string $id): ?array
    {
        return $this->decodeIntent($this->row('SELECT * FROM tactical_paper_intent WHERE decision_id=?', [$id]));
    }

    public function orders(string $runId, string $sleeve): array
    {
        return array_map($this->decodeIntent(...), $this->execute('SELECT * FROM tactical_paper_intent
            WHERE run_id=? AND sleeve_id=? ORDER BY created_at,decision_id', [$runId, $sleeve])->fetchAll());
    }

    public function position(string $run, string $sleeve, string $symbol): array
    {
        return $this->row('SELECT * FROM tactical_paper_position WHERE run_id=? AND sleeve_id=? AND symbol=?', [$run, $sleeve, $symbol])
            ?? ['qty' => 0, 'cost_basis' => 0.0];
    }

    public function checkpoint(string $runId, string $scope): ?array
    {
        $row = $this->row('SELECT * FROM tactical_candidate_checkpoint WHERE run_id=? AND scope=?', [$runId, $scope]);
        if ($row !== null) { $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
        return $row;
    }

    /** Compare-and-swap state: a second cycle cannot overwrite a newer close or stop lifecycle. */
    public function saveCheckpoint(string $runId, string $scope, int $expectedVersion, array $payload): void
    {
        $this->transaction(function () use ($runId, $scope, $expectedVersion, $payload): void {
            $this->writeCheckpoint($runId, $scope, $expectedVersion, $payload);
        });
    }

    private function writeCheckpoint(string $runId, string $scope, int $expectedVersion, array $payload): void
    {
        $this->assertRun($runId); $old = $this->checkpoint($runId, $scope);
        if ((int) ($old['version'] ?? 0) !== $expectedVersion || $expectedVersion < 0) { throw new \RuntimeException('Candidate checkpoint version conflict.'); }
        $this->execute('INSERT INTO tactical_candidate_checkpoint(run_id,scope,version,payload,updated_at) VALUES(?,?,?,?,?)
            ON CONFLICT(run_id,scope) DO UPDATE SET version=excluded.version,payload=excluded.payload,updated_at=excluded.updated_at',
            [$runId, $scope, $expectedVersion + 1, CandidateOrder::json($payload), self::now()]);
        $this->event($runId, 'checkpoint:' . $scope . ':' . ($expectedVersion + 1), 'checkpoint',
            ['scope' => $scope, 'version' => $expectedVersion + 1, 'payload' => $payload]);
    }

    public function pause(string $runId, string $reason): void
    {
        $this->assertRun($runId);
        $this->execute('UPDATE tactical_paper_run SET status=\'paused\',last_error_code=?,updated_at=? WHERE run_id=?', [$reason, self::now(), $runId]);
    }

    /** One transaction freezes all twelve risk books, stop latches, circuit and next-session quantities. */
    public function commitClose(string $runId, string $date, string $session, array $bookVersions, array $updates, array $plans, array $provenance): array
    {
        CandidateOrder::date($date); CandidateOrder::date($session);
        if ($session <= $date || count($bookVersions) !== 12 || array_diff_key($bookVersions, $plans) !== []
            || array_diff_key($plans, $bookVersions) !== []) { throw new \InvalidArgumentException('Incomplete candidate close transaction.'); }
        return $this->transaction(function () use ($runId, $date, $session, $bookVersions, $updates, $plans, $provenance): array {
            $this->assertRun($runId);
            $existing = $this->checkpoint($runId, 'close:' . $date);
            $payload = ['date' => $date, 'scheduled_session' => $session, 'plans' => $plans, 'provenance' => $provenance];
            if ($existing !== null) {
                if (CandidateOrder::json($existing['payload']) !== CandidateOrder::json($payload)) { throw new \RuntimeException('Immutable candidate close drift.'); }
                return $existing['payload'];
            }
            $rows = $this->execute('SELECT sleeve_id,version FROM tactical_paper_sleeve WHERE run_id=?', [$runId])->fetchAll();
            if (count($rows) !== 12) { throw new \RuntimeException('Candidate sleeve set changed during close.'); }
            foreach ($rows as $row) {
                $id = $row['sleeve_id'];
                if (($bookVersions[$id] ?? null) !== (int) $row['version'] || !isset($updates['sleeve:' . $id])
                    || ($updates['sleeve:' . $id]['payload']['date'] ?? null) !== $date) {
                    throw new \RuntimeException('Candidate close raced with fills or incomplete sleeve state.');
                }
            }
            if (!isset($updates['circuit'])) { throw new \RuntimeException('Missing shared circuit checkpoint.'); }
            foreach ($updates as $scope => $u) { $this->writeCheckpoint($runId, $scope, $u['version'], $u['payload']); }
            foreach ($plans as $id => $plan) {
                $this->execute('UPDATE tactical_paper_sleeve SET last_signal_date=?,last_session=?,payload=?,version=version+1,updated_at=?
                    WHERE run_id=? AND sleeve_id=?', [$date, $session, CandidateOrder::json($plan), self::now(), $runId, $id]);
            }
            $this->writeCheckpoint($runId, 'close:' . $date, 0, $payload);
            $latest = $this->checkpoint($runId, 'latest_close');
            if ($latest !== null && $latest['payload']['date'] >= $date) { throw new \RuntimeException('Candidate close order regression.'); }
            $this->writeCheckpoint($runId, 'latest_close', (int) ($latest['version'] ?? 0), $payload);
            return $payload;
        });
    }

    private function assertSellCapacity(array $i, ?string $exclude = null): void
    {
        $owned = CandidateOrder::quantity($this->position($i['run_id'], $i['sleeve_id'], $i['symbol'])['qty'], true);
        $reserved = 0;
        foreach ($this->active($i['run_id'], $i['sleeve_id']) as $open) {
            if ($open['decision_id'] !== $exclude && $open['symbol'] === $i['symbol'] && $open['side'] === 'sell') {
                $reserved += CandidateOrder::quantity($open['requested_qty']) - CandidateOrder::quantity($open['cumulative_filled_qty'], true);
            }
        }
        if ($reserved + (int) $i['requested_qty'] > $owned) { throw new \RuntimeException('Candidate sell exceeds unreserved sleeve-owned shares.'); }
    }

    private function fill(array $i, int $qty, float $notional): void
    {
        $position = $this->position($i['run_id'], $i['sleeve_id'], $i['symbol']);
        $oldQty = CandidateOrder::quantity($position['qty'], true); $cost = (float) $position['cost_basis'];
        if ($i['side'] === 'buy') { $newQty = $oldQty + $qty; $cost += $notional; $cash = -$notional; }
        else {
            if ($qty > $oldQty) { throw new \RuntimeException('Candidate fill would short another sleeve.'); }
            $newQty = $oldQty - $qty; $cost = $newQty === 0 ? 0.0 : $cost * $newQty / $oldQty; $cash = $notional;
        }
        $this->execute('INSERT INTO tactical_paper_position(run_id,sleeve_id,symbol,qty,cost_basis,updated_at) VALUES(?,?,?,?,?,?)
            ON CONFLICT(run_id,sleeve_id,symbol) DO UPDATE SET qty=excluded.qty,cost_basis=excluded.cost_basis,updated_at=excluded.updated_at',
            [$i['run_id'], $i['sleeve_id'], $i['symbol'], $newQty, $cost, self::now()]);
        $this->execute('UPDATE tactical_paper_sleeve SET cash=cash+?,version=version+1,updated_at=? WHERE run_id=? AND sleeve_id=?',
            [$cash, self::now(), $i['run_id'], $i['sleeve_id']]);
        if ($i['leg'] === 'protective_stop') {
            $scope = 'stop_latch:' . $i['sleeve_id']; $latch = $this->checkpoint($i['run_id'], $scope);
            $this->writeCheckpoint($i['run_id'], $scope, (int) ($latch['version'] ?? 0), ['pending' => true,
                'decision_id' => $i['decision_id'], 'observed_at' => self::now(), 'symbol' => $i['symbol']]);
        }
        if ($newQty === 0) {
            $scope = 'protection:' . $i['sleeve_id'] . ':' . $i['symbol'];
            $checkpoint = $this->checkpoint($i['run_id'], $scope);
            if ($checkpoint !== null && $checkpoint['payload'] !== []) {
                $this->writeCheckpoint($i['run_id'], $scope, (int) $checkpoint['version'], []);
            }
        }
    }

    private function requireIntent(string $id): array
    {
        $i = $this->intent($id) ?? throw new \RuntimeException('Unknown candidate intent.');
        CandidateOrder::assert($i); return $i;
    }

    private function assertRun(string $id): array
    {
        $run = $this->run($id) ?? throw new \RuntimeException('Unknown candidate run.');
        $contract = json_decode($run['data_contract'], true, 512, JSON_THROW_ON_ERROR);
        if (($contract['execution_contract'] ?? null) !== CandidateOrder::CONTRACT || ($contract['paper_only'] ?? null) !== true) {
            throw new \RuntimeException('Refusing to mutate a non-candidate run.');
        }
        return $run;
    }

    private function event(string $runId, string $key, string $kind, array $payload): void
    {
        $json = CandidateOrder::json($payload);
        $old = $this->row('SELECT kind,payload FROM tactical_candidate_event WHERE run_id=? AND event_key=?', [$runId, $key]);
        if ($old !== null) {
            if ($old['kind'] !== $kind || $old['payload'] !== $json) { throw new \RuntimeException('Candidate event identity drift.'); }
            return;
        }
        $this->execute('INSERT INTO tactical_candidate_event(run_id,event_key,kind,payload,created_at) VALUES(?,?,?,?,?)', [$runId, $key, $kind, $json, self::now()]);
    }

    private function row(string $sql, array $values): ?array
    {
        $s = $this->execute($sql, $values); $r = $s->fetch(); return $r === false ? null : $r;
    }

    private function decodeIntent(?array $row): ?array
    {
        if ($row !== null) { $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
        return $row;
    }

    private function execute(string $sql, array $values): \PDOStatement
    {
        $s = $this->db->prepare($sql); $s->execute($values); return $s;
    }

    private function transaction(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $value = $fn(); $this->db->exec('COMMIT'); return $value; }
        catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    private static function now(): string { return gmdate(DATE_ATOM); }
}
