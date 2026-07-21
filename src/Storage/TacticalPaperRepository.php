<?php

declare(strict_types=1);

namespace FulltimeTrading\Storage;

use FulltimeTrading\Trading\TacticalRotationPaperPlanner;
use PDO;

/**
 * Isolated, append-audited state for the four independent hybrid-v4 sleeves.
 * Legacy paper tables intentionally remain untouched during transition.
 */
final class TacticalPaperRepository
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create tactical DB directory: ' . $dir);
        }
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=FULL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
    }

    public function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS tactical_paper_run (
    run_id TEXT PRIMARY KEY,
    profile TEXT NOT NULL,
    strategy_hash TEXT NOT NULL,
    runtime_hash TEXT NOT NULL,
    data_contract TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('transition','active','paused')),
    initial_equity REAL,
    activated_at TEXT,
    live_review_not_before TEXT NOT NULL,
    legacy_snapshot TEXT NOT NULL DEFAULT '{}',
    flat_candidate_at TEXT,
    flat_candidate_fingerprint TEXT,
    last_error_code TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tactical_paper_sleeve (
    run_id TEXT NOT NULL,
    sleeve_id TEXT NOT NULL,
    allocation REAL NOT NULL CHECK(allocation > 0 AND allocation < 1),
    cash REAL NOT NULL DEFAULT 0,
    initial_equity REAL NOT NULL DEFAULT 0,
    last_signal_date TEXT,
    last_session TEXT,
    version INTEGER NOT NULL DEFAULT 0,
    payload TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL,
    PRIMARY KEY(run_id, sleeve_id),
    FOREIGN KEY(run_id) REFERENCES tactical_paper_run(run_id)
);

CREATE TABLE IF NOT EXISTS tactical_paper_position (
    run_id TEXT NOT NULL,
    sleeve_id TEXT NOT NULL,
    symbol TEXT NOT NULL,
    qty REAL NOT NULL DEFAULT 0 CHECK(qty >= 0),
    cost_basis REAL NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    PRIMARY KEY(run_id, sleeve_id, symbol),
    FOREIGN KEY(run_id, sleeve_id) REFERENCES tactical_paper_sleeve(run_id, sleeve_id)
);

CREATE TABLE IF NOT EXISTS tactical_paper_intent (
    decision_id TEXT PRIMARY KEY,
    epoch_key TEXT NOT NULL UNIQUE,
    run_id TEXT NOT NULL,
    sleeve_id TEXT NOT NULL,
    signal_date TEXT NOT NULL,
    scheduled_session TEXT NOT NULL,
    leg TEXT NOT NULL,
    symbol TEXT NOT NULL,
    side TEXT NOT NULL CHECK(side IN ('buy','sell')),
    requested_qty REAL NOT NULL CHECK(requested_qty > 0),
    client_order_id TEXT NOT NULL UNIQUE,
    order_id TEXT,
    status TEXT NOT NULL,
    cumulative_filled_qty REAL NOT NULL DEFAULT 0,
    cumulative_fill_notional REAL NOT NULL DEFAULT 0,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    payload TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    submitted_at TEXT,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(run_id, sleeve_id) REFERENCES tactical_paper_sleeve(run_id, sleeve_id)
);

CREATE INDEX IF NOT EXISTS idx_tactical_intent_run_status
    ON tactical_paper_intent(run_id, status);
CREATE INDEX IF NOT EXISTS idx_tactical_intent_order
    ON tactical_paper_intent(order_id);

CREATE TABLE IF NOT EXISTS tactical_paper_fill_audit (
    fill_key TEXT PRIMARY KEY,
    decision_id TEXT NOT NULL,
    order_id TEXT,
    cumulative_qty REAL NOT NULL,
    cumulative_notional REAL NOT NULL,
    recorded_at TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY(decision_id) REFERENCES tactical_paper_intent(decision_id)
);

CREATE TABLE IF NOT EXISTS tactical_paper_snapshot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL,
    captured_at TEXT NOT NULL,
    equity REAL NOT NULL,
    cash REAL NOT NULL,
    buying_power REAL NOT NULL,
    positions TEXT NOT NULL,
    open_orders TEXT NOT NULL,
    reconciliation_status TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY(run_id) REFERENCES tactical_paper_run(run_id)
);

CREATE INDEX IF NOT EXISTS idx_tactical_snapshot_run_time
    ON tactical_paper_snapshot(run_id, captured_at);

CREATE TABLE IF NOT EXISTS tactical_paper_notification (
    notification_key TEXT PRIMARY KEY,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT,
    delivered_at TEXT,
    next_attempt_at TEXT,
    created_at TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}'
);
SQL);
    }

    /**
     * @param array{run_id:string,profile:string,strategy_hash:string,runtime_hash:string,data_contract:array<string,mixed>,live_review_not_before:string} $identity
     * @param array<string,float> $allocations
     */
    public function ensureRun(array $identity, array $allocations): array
    {
        $existing = $this->run($identity['run_id']);
        $contract = self::json($identity['data_contract']);
        if ($existing !== null) {
            foreach (['profile', 'strategy_hash', 'runtime_hash', 'live_review_not_before'] as $field) {
                if (!hash_equals((string) $existing[$field], (string) $identity[$field])) {
                    throw new \RuntimeException('Tactical run identity drift: ' . $field);
                }
            }
            if (!hash_equals((string) $existing['data_contract'], $contract)) {
                throw new \RuntimeException('Tactical run identity drift: data_contract');
            }
            $this->assertSleeveDefinitions($identity['run_id'], $allocations);

            return $existing;
        }

        $sum = array_sum($allocations);
        if (count($allocations) !== 4 || abs($sum - 1.0) > 1.0e-9) {
            throw new \InvalidArgumentException('Paper hybrid-v4 requires exactly four sleeves summing to one.');
        }
        $now = self::now();
        $this->transaction(function () use ($identity, $allocations, $contract, $now): void {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tactical_paper_run(
                    run_id, profile, strategy_hash, runtime_hash, data_contract, status,
                    live_review_not_before, created_at, updated_at
                 ) VALUES(:run_id,:profile,:strategy_hash,:runtime_hash,:data_contract,:status,
                    :live_review_not_before,:created_at,:updated_at)'
            );
            $stmt->execute([
                ':run_id' => $identity['run_id'],
                ':profile' => $identity['profile'],
                ':strategy_hash' => $identity['strategy_hash'],
                ':runtime_hash' => $identity['runtime_hash'],
                ':data_contract' => $contract,
                ':status' => 'transition',
                ':live_review_not_before' => $identity['live_review_not_before'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $sleeve = $this->pdo->prepare(
                'INSERT INTO tactical_paper_sleeve(run_id,sleeve_id,allocation,updated_at)
                 VALUES(:run_id,:sleeve_id,:allocation,:updated_at)'
            );
            foreach ($allocations as $sleeveId => $allocation) {
                if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $sleeveId) || $allocation <= 0.0 || $allocation >= 1.0) {
                    throw new \InvalidArgumentException('Invalid tactical sleeve definition.');
                }
                $sleeve->execute([
                    ':run_id' => $identity['run_id'],
                    ':sleeve_id' => $sleeveId,
                    ':allocation' => $allocation,
                    ':updated_at' => $now,
                ]);
            }
        });

        return $this->run($identity['run_id']) ?? throw new \RuntimeException('Unable to initialize tactical run.');
    }

    /** @return array<string,mixed>|null */
    public function run(string $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tactical_paper_run WHERE run_id = :run_id');
        $stmt->execute([':run_id' => $runId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $legacySnapshot */
    public function activate(string $runId, float $equity, array $legacySnapshot): void
    {
        if (!is_finite($equity) || $equity <= 0.0) {
            throw new \InvalidArgumentException('Activation equity must be positive.');
        }
        if (($legacySnapshot['positions'] ?? null) !== []
            || ($legacySnapshot['open_orders'] ?? null) !== []
            || ($legacySnapshot['adoption'] ?? null) !== 'flat_account_only'
            || (int) ($legacySnapshot['stable_for_seconds'] ?? 0) < 120) {
            throw new \RuntimeException('Tactical activation requires an explicitly verified flat broker snapshot.');
        }
        $run = $this->run($runId);
        if ($run === null || !in_array($run['status'], ['transition', 'active'], true)) {
            throw new \RuntimeException('Tactical run cannot be activated from its current state.');
        }
        if ($run['status'] === 'active') {
            return;
        }
        $now = self::now();
        $this->transaction(function () use ($runId, $equity, $legacySnapshot, $now): void {
            $sleeves = $this->sleeves($runId);
            $allocated = 0.0;
            $last = (string) array_key_last($sleeves);
            $update = $this->pdo->prepare(
                'UPDATE tactical_paper_sleeve
                 SET cash=:cash, initial_equity=:initial_equity, version=version+1, updated_at=:updated_at
                 WHERE run_id=:run_id AND sleeve_id=:sleeve_id'
            );
            foreach ($sleeves as $id => $sleeve) {
                $capital = $id === $last ? $equity - $allocated : $equity * (float) $sleeve['allocation'];
                $allocated += $capital;
                $update->execute([
                    ':cash' => $capital,
                    ':initial_equity' => $capital,
                    ':updated_at' => $now,
                    ':run_id' => $runId,
                    ':sleeve_id' => $id,
                ]);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE tactical_paper_run SET status=:status, initial_equity=:equity,
                    activated_at=:activated_at, legacy_snapshot=:legacy_snapshot,
                    last_error_code=NULL, updated_at=:updated_at WHERE run_id=:run_id'
            );
            $stmt->execute([
                ':status' => 'active',
                ':equity' => $equity,
                ':activated_at' => $now,
                ':legacy_snapshot' => self::json($legacySnapshot),
                ':updated_at' => $now,
                ':run_id' => $runId,
            ]);
        });
    }

    public function observeFlatHandoff(string $runId, string $fingerprint, int $minimumStableSeconds = 120): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException('Flat handoff fingerprint must be SHA-256.');
        }
        $run = $this->run($runId);
        if ($run === null || (string) $run['status'] !== 'transition') {
            return false;
        }
        $candidateAt = (string) ($run['flat_candidate_at'] ?? '');
        $candidateFingerprint = (string) ($run['flat_candidate_fingerprint'] ?? '');
        if ($candidateAt !== '' && hash_equals($candidateFingerprint, $fingerprint)) {
            try {
                return time() - (new \DateTimeImmutable($candidateAt))->getTimestamp() >= max(30, $minimumStableSeconds);
            } catch (\Throwable) {
                // Replace an invalid candidate below.
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_run SET flat_candidate_at=:at,flat_candidate_fingerprint=:fingerprint,
                updated_at=:updated_at WHERE run_id=:run_id AND status=\'transition\''
        );
        $stmt->execute([
            ':at' => self::now(),
            ':fingerprint' => $fingerprint,
            ':updated_at' => self::now(),
            ':run_id' => $runId,
        ]);

        return false;
    }

    public function clearFlatHandoffCandidate(string $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_run SET flat_candidate_at=NULL,flat_candidate_fingerprint=NULL,
                updated_at=:updated_at WHERE run_id=:run_id AND status=\'transition\''
        );
        $stmt->execute([':updated_at' => self::now(), ':run_id' => $runId]);
    }

    public function setRunError(string $runId, ?string $code): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_run SET last_error_code=:code, updated_at=:updated_at WHERE run_id=:run_id'
        );
        $stmt->execute([':code' => $code, ':updated_at' => self::now(), ':run_id' => $runId]);
    }

    /** @param array<string,mixed> $payload */
    public function recordSleeveCheckpoint(
        string $runId,
        string $sleeveId,
        string $signalDate,
        string $sessionDate,
        array $payload,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_sleeve SET last_signal_date=:signal_date,last_session=:session,
                payload=:payload,version=version+1,updated_at=:updated_at
             WHERE run_id=:run_id AND sleeve_id=:sleeve_id'
        );
        $stmt->execute([
            ':signal_date' => $signalDate,
            ':session' => $sessionDate,
            ':payload' => self::json($payload),
            ':updated_at' => self::now(),
            ':run_id' => $runId,
            ':sleeve_id' => $sleeveId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Unknown tactical sleeve checkpoint.');
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function sleeves(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_sleeve WHERE run_id=:run_id ORDER BY sleeve_id'
        );
        $stmt->execute([':run_id' => $runId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['sleeve_id']] = $this->decodePayload($row);
        }

        return $result;
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    public function positions(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_position WHERE run_id=:run_id AND qty > 0 ORDER BY sleeve_id,symbol'
        );
        $stmt->execute([':run_id' => $runId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['sleeve_id']][(string) $row['symbol']] = $row;
        }

        return $result;
    }

    /** @return array<string,float> */
    public function expectedBrokerPositions(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT symbol,SUM(qty) AS qty FROM tactical_paper_position
             WHERE run_id=:run_id AND qty > 0 GROUP BY symbol ORDER BY symbol'
        );
        $stmt->execute([':run_id' => $runId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['symbol']] = (float) $row['qty'];
        }

        return $result;
    }

    /** @param array<string,mixed> $intent */
    public function createIntent(array $intent): array
    {
        TacticalRotationPaperPlanner::assertExecutionIdentity($intent);
        $epochKey = (string) ($intent['epoch_key'] ?? '');
        if ($epochKey === '') {
            throw new \InvalidArgumentException('Tactical intent requires an immutable epoch key.');
        }
        $epoch = $this->intentByEpoch($epochKey);
        if ($epoch !== null) {
            if (!hash_equals((string) $epoch['decision_id'], (string) $intent['decision_id'])
                || !hash_equals((string) $epoch['client_order_id'], (string) $intent['client_order_id'])
                || abs((float) $epoch['requested_qty'] - (float) $intent['requested_qty']) > 1.0e-9
                || !hash_equals((string) $epoch['symbol'], strtoupper((string) $intent['symbol']))
                || !hash_equals((string) $epoch['side'], strtolower((string) $intent['side']))) {
                if ($this->canRebindPristineRiskExit($epoch, $intent)) {
                    return $this->rebindPristineRiskExit($epoch, $intent);
                }
                throw new \RuntimeException('Immutable tactical target epoch drift.');
            }

            return $epoch;
        }
        $existing = $this->intent((string) $intent['decision_id']);
        if ($existing !== null) {
            return $existing;
        }
        $run = $this->run((string) ($intent['run_id'] ?? ''));
        $pausedRecovery = $run !== null
            && (string) $run['status'] === 'paused'
            && $this->terminalRecoverySellAllowed($intent);
        if ($run === null || ((string) $run['status'] !== 'active' && !$pausedRecovery)) {
            throw new \RuntimeException('New tactical risk intents require an active run.');
        }
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO tactical_paper_intent(
                decision_id,epoch_key,run_id,sleeve_id,signal_date,scheduled_session,leg,symbol,side,
                requested_qty,client_order_id,status,payload,created_at,updated_at
             ) VALUES(:decision_id,:epoch_key,:run_id,:sleeve_id,:signal_date,:scheduled_session,:leg,:symbol,:side,
                :requested_qty,:client_order_id,:status,:payload,:created_at,:updated_at)'
        );
        $stmt->execute([
            ':decision_id' => $intent['decision_id'],
            ':epoch_key' => $epochKey,
            ':run_id' => $intent['run_id'],
            ':sleeve_id' => $intent['sleeve_id'],
            ':signal_date' => $intent['signal_date'],
            ':scheduled_session' => $intent['scheduled_session'],
            ':leg' => $intent['leg'],
            ':symbol' => strtoupper((string) $intent['symbol']),
            ':side' => strtolower((string) $intent['side']),
            ':requested_qty' => (float) $intent['requested_qty'],
            ':client_order_id' => $intent['client_order_id'],
            ':status' => 'planned',
            ':payload' => self::json($intent['payload'] ?? []),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->intent((string) $intent['decision_id']) ?? throw new \RuntimeException('Intent insert failed.');
    }

    /** @return array<string,mixed>|null */
    public function intent(string $decisionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tactical_paper_intent WHERE decision_id=:decision_id');
        $stmt->execute([':decision_id' => $decisionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->decodePayload($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function intentByEpoch(string $epochKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tactical_paper_intent WHERE epoch_key=:epoch_key');
        $stmt->execute([':epoch_key' => $epochKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->decodePayload($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function activeIntents(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_intent WHERE run_id=:run_id
             AND status NOT IN (\'filled\',\'canceled\',\'cancelled\',\'expired\',\'rejected\')
             ORDER BY created_at,decision_id'
        );
        $stmt->execute([':run_id' => $runId]);

        return array_map(fn (array $row): array => $this->decodePayload($row), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function terminalIncompleteIntents(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_intent WHERE run_id=:run_id
             AND status IN (\'filled\',\'canceled\',\'cancelled\',\'expired\',\'rejected\',\'done_for_day\',\'ambiguous_missed\')
             AND cumulative_filled_qty < requested_qty - 0.000000001
             ORDER BY created_at,decision_id'
        );
        $stmt->execute([':run_id' => $runId]);

        return array_map(fn (array $row): array => $this->decodePayload($row), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $intent */
    public static function isTerminalIncompleteIntent(array $intent): bool
    {
        return in_array(strtolower((string) ($intent['status'] ?? '')), [
            'filled', 'canceled', 'cancelled', 'expired', 'rejected', 'done_for_day', 'ambiguous_missed',
        ], true)
            && (float) ($intent['cumulative_filled_qty'] ?? 0.0) + 1.0e-9
                < (float) ($intent['requested_qty'] ?? 0.0);
    }

    /** @return list<array<string,mixed>> */
    public function intents(string $runId, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_intent WHERE run_id=:run_id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':run_id', $runId);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->decodePayload($row), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $order */
    public function applyBrokerOrder(string $decisionId, array $order): array
    {
        return $this->transaction(function () use ($decisionId, $order): array {
            $stmt = $this->pdo->prepare('SELECT * FROM tactical_paper_intent WHERE decision_id=:id');
            $stmt->execute([':id' => $decisionId]);
            $intent = $stmt->fetch();
            if (!is_array($intent)) {
                throw new \RuntimeException('Cannot apply broker order to unknown decision.');
            }
            $intent = $this->decodePayload($intent);
            TacticalRotationPaperPlanner::assertExecutionIdentity($intent);
            $brokerClientId = (string) ($order['client_order_id'] ?? '');
            if ($brokerClientId === '' || !hash_equals((string) $intent['client_order_id'], $brokerClientId)) {
                throw new \RuntimeException('Broker client_order_id mismatch.');
            }
            if (strtoupper((string) ($order['symbol'] ?? '')) !== (string) $intent['symbol']
                || strtolower((string) ($order['side'] ?? '')) !== (string) $intent['side']) {
                throw new \RuntimeException('Broker order symbol/side mismatch.');
            }
            $expectedTif = strtolower((string) ($intent['payload']['time_in_force'] ?? 'opg'));
            if (!in_array($expectedTif, ['opg', 'day'], true)
                || abs((float) ($order['qty'] ?? 0.0) - (float) $intent['requested_qty']) > 1.0e-6
                || strtolower((string) ($order['type'] ?? '')) !== 'market'
                || strtolower((string) ($order['time_in_force'] ?? '')) !== $expectedTif) {
                throw new \RuntimeException('Broker order body drift.');
            }
            if (($order['extended_hours'] ?? null) !== false) {
                throw new \RuntimeException('Broker OPG order unexpectedly enables extended hours.');
            }
            if (trim((string) ($order['id'] ?? '')) === '') {
                throw new \RuntimeException('Broker order id is missing.');
            }
            if ((string) $intent['order_id'] !== ''
                && !hash_equals((string) $intent['order_id'], (string) ($order['id'] ?? ''))) {
                throw new \RuntimeException('Broker order id changed for an existing intent.');
            }
            $status = strtolower((string) ($order['status'] ?? 'unknown'));
            $allowedStatuses = [
                'pending_new', 'accepted', 'new', 'partially_filled', 'filled',
                'canceled', 'cancelled', 'expired', 'rejected', 'done_for_day',
            ];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new \RuntimeException('Unknown broker order status.');
            }
            $oldStatus = strtolower((string) $intent['status']);
            $terminal = ['filled', 'canceled', 'cancelled', 'expired', 'rejected'];
            if (in_array($oldStatus, $terminal, true) && $status !== $oldStatus) {
                throw new \RuntimeException('Broker order terminal status regression.');
            }
            $rank = ['pending_new' => 1, 'accepted' => 2, 'new' => 2, 'partially_filled' => 3, 'done_for_day' => 3, 'filled' => 4];
            if (isset($rank[$oldStatus], $rank[$status]) && $rank[$status] < $rank[$oldStatus]) {
                throw new \RuntimeException('Broker order status regression.');
            }
            $newQty = max(0.0, (float) ($order['filled_qty'] ?? 0.0));
            $oldQty = (float) $intent['cumulative_filled_qty'];
            if ($newQty + 1.0e-9 < $oldQty || $newQty > (float) $intent['requested_qty'] + 1.0e-6) {
                throw new \RuntimeException('Non-monotonic or excessive broker cumulative fill.');
            }
            $avg = $newQty > 0.0 ? (float) ($order['filled_avg_price'] ?? 0.0) : 0.0;
            if ($newQty > 0.0 && $avg <= 0.0) {
                throw new \RuntimeException('Filled broker order is missing a positive average price.');
            }
            $newNotional = $newQty * $avg;
            $oldNotional = (float) $intent['cumulative_fill_notional'];
            if ($newNotional + 0.01 < $oldNotional) {
                throw new \RuntimeException('Non-monotonic broker cumulative fill notional.');
            }
            $deltaQty = $newQty - $oldQty;
            $deltaNotional = $newNotional - $oldNotional;
            if ($deltaQty <= 1.0e-9 && abs($deltaNotional) > 0.01) {
                throw new \RuntimeException('Broker average-price correction requires fill-activity reconciliation.');
            }
            if ($deltaQty > 1.0e-9) {
                $this->applyFillToSleeve($intent, $deltaQty, $deltaNotional);
                $fillKey = hash('sha256', (string) ($order['id'] ?? '') . '|' . sprintf('%.8F', $newQty) . '|' . sprintf('%.8F', $newNotional));
                $fill = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO tactical_paper_fill_audit(
                        fill_key,decision_id,order_id,cumulative_qty,cumulative_notional,recorded_at,payload
                     ) VALUES(:fill_key,:decision_id,:order_id,:qty,:notional,:recorded_at,:payload)'
                );
                $fill->execute([
                    ':fill_key' => $fillKey,
                    ':decision_id' => $decisionId,
                    ':order_id' => $order['id'] ?? null,
                    ':qty' => $newQty,
                    ':notional' => $newNotional,
                    ':recorded_at' => self::now(),
                    ':payload' => self::json(['status' => $order['status'] ?? null]),
                ]);
            }
            $update = $this->pdo->prepare(
                'UPDATE tactical_paper_intent SET order_id=:order_id,status=:status,
                    cumulative_filled_qty=:qty,cumulative_fill_notional=:notional,
                    submitted_at=COALESCE(submitted_at,:submitted_at),updated_at=:updated_at
                 WHERE decision_id=:decision_id'
            );
            $update->execute([
                ':order_id' => $order['id'] ?? null,
                ':status' => $status,
                ':qty' => $newQty,
                ':notional' => $newNotional,
                ':submitted_at' => $order['submitted_at'] ?? self::now(),
                ':updated_at' => self::now(),
                ':decision_id' => $decisionId,
            ]);

            $updatedIntent = array_replace($intent, [
                'status' => $status,
                'cumulative_filled_qty' => $newQty,
                'cumulative_fill_notional' => $newNotional,
            ]);
            if (self::isTerminalIncompleteIntent($updatedIntent)) {
                $pause = $this->pdo->prepare(
                    'UPDATE tactical_paper_run SET status=:status,last_error_code=:error_code,updated_at=:updated_at
                     WHERE run_id=:run_id AND status IN (\'active\',\'paused\')'
                );
                $pause->execute([
                    ':status' => 'paused',
                    ':error_code' => 'terminal_incomplete_order:' . substr($decisionId, 0, 12),
                    ':updated_at' => self::now(),
                    ':run_id' => $intent['run_id'],
                ]);
            }

            return $this->intent($decisionId) ?? throw new \RuntimeException('Intent update failed.');
        });
    }

    public function markSubmitting(string $decisionId): bool
    {
        $intent = $this->intent($decisionId);
        if ($intent === null) {
            return false;
        }
        $run = $this->run((string) $intent['run_id']);
        $requiredRunStatus = 'active';
        if ($run !== null
            && (string) $run['status'] === 'paused'
            && $this->terminalRecoverySellAllowed($intent)) {
            $requiredRunStatus = 'paused';
        } elseif ($run === null || (string) $run['status'] !== 'active') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_intent SET status=:status,attempt_count=attempt_count+1,
                updated_at=:updated_at WHERE decision_id=:decision_id AND status IN (\'planned\',\'ambiguous\')
                AND EXISTS (
                    SELECT 1 FROM tactical_paper_run
                    WHERE tactical_paper_run.run_id=tactical_paper_intent.run_id
                    AND tactical_paper_run.status=:run_status
                )'
        );
        $stmt->execute([
            ':status' => 'submitting',
            ':updated_at' => self::now(),
            ':decision_id' => $decisionId,
            ':run_status' => $requiredRunStatus,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markAmbiguous(string $decisionId, string $reasonCode): void
    {
        $intent = $this->intent($decisionId);
        if ($intent === null || !in_array((string) $intent['status'], ['submitting', 'ambiguous'], true)) {
            return;
        }
        $payload = (array) ($intent['payload'] ?? []);
        $payload['submit_error_code'] = $reasonCode;
        $payload['ambiguous_since_at'] ??= self::now();
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_intent SET status=:status,payload=:payload,updated_at=:updated_at
             WHERE decision_id=:decision_id AND status IN (\'submitting\',\'ambiguous\')'
        );
        $stmt->execute([
            ':status' => 'ambiguous',
            ':payload' => self::json($payload),
            ':updated_at' => self::now(),
            ':decision_id' => $decisionId,
        ]);
    }

    /** @return array<string,mixed> */
    public function recordAmbiguousNotFound(string $decisionId): array
    {
        $intent = $this->intent($decisionId);
        if ($intent === null || !in_array((string) $intent['status'], ['submitting', 'ambiguous'], true)) {
            throw new \RuntimeException('Only unresolved submit intents may record a confirmed 404.');
        }
        $payload = (array) ($intent['payload'] ?? []);
        $payload['ambiguous_not_found_count'] = (int) ($payload['ambiguous_not_found_count'] ?? 0) + 1;
        $payload['ambiguous_last_not_found_at'] = self::now();
        // Preserve updated_at: it is the retry-backoff anchor, not a polling
        // timestamp. The lookup timestamps remain append-visible in payload.
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_intent SET payload=:payload
             WHERE decision_id=:decision_id AND status IN (\'submitting\',\'ambiguous\')'
        );
        $stmt->execute([
            ':payload' => self::json($payload),
            ':decision_id' => $decisionId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Ambiguous 404 observation lost its compare-and-swap.');
        }

        return $this->intent($decisionId) ?? throw new \RuntimeException('Ambiguous 404 observation failed.');
    }

    /** @return array<string,mixed> */
    public function markAmbiguousWindowMissed(string $decisionId, string $reasonCode): array
    {
        return $this->transaction(function () use ($decisionId, $reasonCode): array {
            $intent = $this->intent($decisionId);
            if ($intent === null || !in_array((string) $intent['status'], ['submitting', 'ambiguous'], true)) {
                throw new \RuntimeException('Only unresolved submit intents may miss their execution window.');
            }
            $payload = (array) ($intent['payload'] ?? []);
            $payload['submit_error_code'] = $reasonCode;
            $payload['ambiguous_window_missed'] = true;
            $payload['ambiguous_window_missed_at'] = self::now();
            $stmt = $this->pdo->prepare(
                'UPDATE tactical_paper_intent SET status=:status,payload=:payload,updated_at=:updated_at
                 WHERE decision_id=:decision_id AND status IN (\'submitting\',\'ambiguous\')'
            );
            $stmt->execute([
                ':status' => 'ambiguous_missed',
                ':payload' => self::json($payload),
                ':updated_at' => self::now(),
                ':decision_id' => $decisionId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Ambiguous missed-window latch lost its compare-and-swap.');
            }
            $pause = $this->pdo->prepare(
                'UPDATE tactical_paper_run SET status=:status,last_error_code=:error_code,updated_at=:updated_at
                 WHERE run_id=:run_id AND status IN (\'active\',\'paused\')'
            );
            $pause->execute([
                ':status' => 'paused',
                ':error_code' => $reasonCode,
                ':updated_at' => self::now(),
                ':run_id' => $intent['run_id'],
            ]);

            return $this->intent($decisionId)
                ?? throw new \RuntimeException('Ambiguous missed-window latch failed.');
        });
    }

    /** @param array<string,mixed> $snapshot */
    public function saveSnapshot(string $runId, array $snapshot): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tactical_paper_snapshot(
                run_id,captured_at,equity,cash,buying_power,positions,open_orders,reconciliation_status,payload
             ) VALUES(:run_id,:captured_at,:equity,:cash,:buying_power,:positions,:open_orders,:status,:payload)'
        );
        $stmt->execute([
            ':run_id' => $runId,
            ':captured_at' => $snapshot['captured_at'] ?? self::now(),
            ':equity' => (float) ($snapshot['equity'] ?? 0.0),
            ':cash' => (float) ($snapshot['cash'] ?? 0.0),
            ':buying_power' => (float) ($snapshot['buying_power'] ?? 0.0),
            ':positions' => self::json($snapshot['positions'] ?? []),
            ':open_orders' => self::json($snapshot['open_orders'] ?? []),
            ':status' => (string) ($snapshot['reconciliation_status'] ?? 'unknown'),
            ':payload' => self::json($snapshot['payload'] ?? []),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function snapshots(string $runId, ?string $since = null): array
    {
        $sql = 'SELECT * FROM tactical_paper_snapshot WHERE run_id=:run_id';
        $params = [':run_id' => $runId];
        if ($since !== null) {
            $sql .= ' AND captured_at >= :since';
            $params[':since'] = $since;
        }
        $sql .= ' ORDER BY captured_at,id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            foreach (['positions', 'open_orders', 'payload'] as $field) {
                $decoded = json_decode((string) ($row[$field] ?? '[]'), true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function notificationDelivered(string $key): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tactical_paper_notification WHERE notification_key=:key AND delivered_at IS NOT NULL'
        );
        $stmt->execute([':key' => $key]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $payload */
    public function queueNotification(string $key, string $message, array $payload = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO tactical_paper_notification(
                notification_key,message,status,created_at,payload
             ) VALUES(:key,:message,:status,:created_at,:payload)'
        );
        $stmt->execute([
            ':key' => $key,
            ':message' => $message,
            ':status' => 'pending',
            ':created_at' => self::now(),
            ':payload' => self::json($payload),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function pendingNotifications(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_notification
             WHERE delivered_at IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY created_at LIMIT :limit'
        );
        $stmt->bindValue(':now', self::now());
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->decodePayload($row), $stmt->fetchAll());
    }

    public function markNotificationAttempted(string $key, int $retrySeconds = 300): void
    {
        $now = new \DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_notification SET status=:status,attempt_count=attempt_count+1,
                attempted_at=:attempted_at,next_attempt_at=:next_attempt_at WHERE notification_key=:key
                AND delivered_at IS NULL'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':attempted_at' => $now->format(\DateTimeInterface::ATOM),
            ':next_attempt_at' => $now->modify('+' . max(60, $retrySeconds) . ' seconds')->format(\DateTimeInterface::ATOM),
            ':key' => $key,
        ]);
    }

    public function markNotificationDelivered(string $key, ?int $telegramMessageId = null): void
    {
        $payloadStatement = $this->pdo->prepare(
            'SELECT payload FROM tactical_paper_notification WHERE notification_key=:key'
        );
        $payloadStatement->execute([':key' => $key]);
        $payloadRaw = $payloadStatement->fetchColumn();
        $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : [];
        $payload = is_array($payload) ? $payload : [];
        if ($telegramMessageId !== null && $telegramMessageId > 0) {
            $payload['telegram_message_id'] = $telegramMessageId;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tactical_paper_notification SET status=:status,delivered_at=:delivered_at,
                next_attempt_at=NULL,payload=:payload
             WHERE notification_key=:key AND delivered_at IS NULL'
        );
        $stmt->execute([
            ':status' => 'delivered',
            ':delivered_at' => self::now(),
            ':payload' => self::json($payload),
            ':key' => $key,
        ]);
    }

    /** @param array<string,mixed> $intent */
    private function applyFillToSleeve(array $intent, float $deltaQty, float $deltaNotional): void
    {
        $runId = (string) $intent['run_id'];
        $sleeveId = (string) $intent['sleeve_id'];
        $symbol = (string) $intent['symbol'];
        $side = (string) $intent['side'];
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tactical_paper_position WHERE run_id=:run_id AND sleeve_id=:sleeve_id AND symbol=:symbol'
        );
        $stmt->execute([':run_id' => $runId, ':sleeve_id' => $sleeveId, ':symbol' => $symbol]);
        $position = $stmt->fetch();
        $oldPositionQty = is_array($position) ? (float) $position['qty'] : 0.0;
        $oldCost = is_array($position) ? (float) $position['cost_basis'] : 0.0;
        if ($side === 'buy') {
            $positionQty = $oldPositionQty + $deltaQty;
            $cost = $oldCost + $deltaNotional;
            $cashDelta = -$deltaNotional;
        } else {
            if ($deltaQty > $oldPositionQty + 1.0e-6) {
                throw new \RuntimeException('Broker sell fill exceeds the sleeve-owned quantity.');
            }
            $positionQty = max(0.0, $oldPositionQty - $deltaQty);
            $cost = $oldPositionQty > 0.0
                ? max(0.0, $oldCost * ($positionQty / $oldPositionQty))
                : 0.0;
            $cashDelta = $deltaNotional;
        }
        $upsert = $this->pdo->prepare(
            'INSERT INTO tactical_paper_position(run_id,sleeve_id,symbol,qty,cost_basis,updated_at)
             VALUES(:run_id,:sleeve_id,:symbol,:qty,:cost_basis,:updated_at)
             ON CONFLICT(run_id,sleeve_id,symbol) DO UPDATE SET
                qty=excluded.qty,cost_basis=excluded.cost_basis,updated_at=excluded.updated_at'
        );
        $upsert->execute([
            ':run_id' => $runId,
            ':sleeve_id' => $sleeveId,
            ':symbol' => $symbol,
            ':qty' => $positionQty,
            ':cost_basis' => $cost,
            ':updated_at' => self::now(),
        ]);
        $cash = $this->pdo->prepare(
            'UPDATE tactical_paper_sleeve SET cash=cash+:cash_delta,version=version+1,updated_at=:updated_at
             WHERE run_id=:run_id AND sleeve_id=:sleeve_id'
        );
        $cash->execute([
            ':cash_delta' => $cashDelta,
            ':updated_at' => self::now(),
            ':run_id' => $runId,
            ':sleeve_id' => $sleeveId,
        ]);
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $replacement */
    private function canRebindPristineRiskExit(array $existing, array $replacement): bool
    {
        try {
            TacticalRotationPaperPlanner::assertExecutionIdentity($existing);
            TacticalRotationPaperPlanner::assertExecutionIdentity($replacement);
        } catch (\Throwable) {
            return false;
        }
        foreach (['epoch_key', 'run_id', 'sleeve_id', 'signal_date', 'scheduled_session', 'leg', 'symbol', 'side'] as $field) {
            if (!hash_equals((string) ($existing[$field] ?? ''), (string) ($replacement[$field] ?? ''))) {
                return false;
            }
        }

        return strtolower((string) ($existing['side'] ?? '')) === 'sell'
            && (string) ($existing['leg'] ?? '') === 'exit'
            && strtolower((string) ($existing['status'] ?? '')) === 'planned'
            && (int) ($existing['attempt_count'] ?? 0) === 0
            && trim((string) ($existing['order_id'] ?? '')) === ''
            && trim((string) ($existing['submitted_at'] ?? '')) === ''
            && (float) ($existing['cumulative_filled_qty'] ?? 0.0) <= 1.0e-9
            && abs((float) ($existing['requested_qty'] ?? 0.0) - (float) ($replacement['requested_qty'] ?? 0.0)) <= 1.0e-9
            && strtolower((string) ($existing['payload']['time_in_force'] ?? '')) === 'opg'
            && strtolower((string) ($replacement['payload']['time_in_force'] ?? '')) === 'day'
            && ($replacement['payload']['risk_exit_day_fallback'] ?? false) === true
            && ($replacement['payload']['terminal_recovery_sell'] ?? false) !== true;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $replacement @return array<string,mixed> */
    private function rebindPristineRiskExit(array $existing, array $replacement): array
    {
        return $this->transaction(function () use ($existing, $replacement): array {
            $stmt = $this->pdo->prepare(
                'UPDATE tactical_paper_intent SET decision_id=:new_decision_id,client_order_id=:client_order_id,
                    payload=:payload,updated_at=:updated_at
                 WHERE decision_id=:old_decision_id AND epoch_key=:epoch_key AND status=\'planned\'
                    AND attempt_count=0 AND order_id IS NULL AND submitted_at IS NULL
                    AND cumulative_filled_qty=0'
            );
            $stmt->execute([
                ':new_decision_id' => $replacement['decision_id'],
                ':client_order_id' => $replacement['client_order_id'],
                ':payload' => self::json($replacement['payload']),
                ':updated_at' => self::now(),
                ':old_decision_id' => $existing['decision_id'],
                ':epoch_key' => $existing['epoch_key'],
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Pristine risk-exit rebind lost its compare-and-swap.');
            }

            return $this->intent((string) $replacement['decision_id'])
                ?? throw new \RuntimeException('Pristine risk-exit rebind failed.');
        });
    }

    /** @param array<string,mixed> $intent */
    private function terminalRecoverySellAllowed(array $intent): bool
    {
        if (strtolower((string) ($intent['side'] ?? '')) !== 'sell'
            || (string) ($intent['leg'] ?? '') !== 'exit'
            || strtolower((string) ($intent['payload']['time_in_force'] ?? '')) !== 'day'
            || ($intent['payload']['risk_exit_day_fallback'] ?? false) !== true
            || ($intent['payload']['terminal_recovery_sell'] ?? false) !== true) {
            return false;
        }
        $dependencyIds = (array) ($intent['payload']['required_terminal_decision_ids'] ?? []);
        if ($dependencyIds === []) {
            return false;
        }
        $remaining = 0.0;
        foreach ($dependencyIds as $decisionId) {
            $dependency = $this->intent((string) $decisionId);
            if ($dependency === null
                || ($dependency['payload']['terminal_recovery_sell'] ?? false) === true
                || !self::isTerminalIncompleteIntent($dependency)) {
                return false;
            }
            foreach (['run_id', 'sleeve_id', 'signal_date', 'scheduled_session', 'symbol'] as $field) {
                if (!hash_equals((string) ($dependency[$field] ?? ''), (string) ($intent[$field] ?? ''))) {
                    return false;
                }
            }
            if (strtolower((string) ($dependency['side'] ?? '')) !== 'sell') {
                return false;
            }
            $remaining += (float) $dependency['requested_qty'] - (float) $dependency['cumulative_filled_qty'];
        }
        $positions = $this->positions((string) $intent['run_id']);
        $owned = (float) ($positions[(string) $intent['sleeve_id']][(string) $intent['symbol']]['qty'] ?? 0.0);

        return abs($remaining - (float) $intent['requested_qty']) <= 1.0e-6
            && (float) $intent['requested_qty'] <= $owned + 1.0e-6;
    }

    /** @param array<string,float> $allocations */
    private function assertSleeveDefinitions(string $runId, array $allocations): void
    {
        $stored = $this->sleeves($runId);
        ksort($allocations, SORT_STRING);
        if (array_keys($stored) !== array_keys($allocations)) {
            throw new \RuntimeException('Tactical run sleeve set drift.');
        }
        foreach ($stored as $id => $row) {
            if (abs((float) $row['allocation'] - (float) $allocations[$id]) > 1.0e-12) {
                throw new \RuntimeException('Tactical run sleeve allocation drift: ' . $id);
            }
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodePayload(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];

        return $row;
    }

    /** @param mixed $value */
    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}
