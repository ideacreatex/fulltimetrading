<?php

declare(strict_types=1);

namespace FulltimeTrading\Storage;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Domain\Trade;
use PDO;

final class SqliteRepository
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create DB directory: ' . $dir);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bars (
    symbol TEXT NOT NULL,
    timeframe TEXT NOT NULL,
    time TEXT NOT NULL,
    open REAL NOT NULL,
    high REAL NOT NULL,
    low REAL NOT NULL,
    close REAL NOT NULL,
    volume REAL NOT NULL,
    PRIMARY KEY (symbol, timeframe, time)
);

CREATE TABLE IF NOT EXISTS signals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    strategy TEXT NOT NULL,
    created_at TEXT NOT NULL,
    entry REAL NOT NULL,
    stop REAL NOT NULL,
    target REAL NOT NULL,
    risk_per_share REAL NOT NULL,
    score REAL NOT NULL,
    reasons TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    strategy TEXT NOT NULL,
    entry_time TEXT NOT NULL,
    exit_time TEXT NOT NULL,
    entry REAL NOT NULL,
    exit REAL NOT NULL,
    shares REAL NOT NULL,
    pnl REAL NOT NULL,
    r_multiple REAL NOT NULL,
    exit_reason TEXT NOT NULL,
    events TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS dashboard_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    captured_at TEXT NOT NULL,
    session_type TEXT NOT NULL,
    code TEXT NOT NULL,
    value REAL,
    payload TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS external_indicator_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    captured_at TEXT NOT NULL,
    symbol TEXT NOT NULL,
    timeframe TEXT NOT NULL,
    indicator TEXT NOT NULL,
    signal TEXT,
    value REAL,
    payload TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS paper_position_state (
    symbol TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    qty REAL NOT NULL DEFAULT 0,
    avg_entry_price REAL NOT NULL DEFAULT 0,
    market_price REAL NOT NULL DEFAULT 0,
    entry_price REAL NOT NULL DEFAULT 0,
    stop_price REAL NOT NULL DEFAULT 0,
    initial_stop_price REAL NOT NULL DEFAULT 0,
    break_even_trigger_price REAL NOT NULL DEFAULT 0,
    target_price REAL NOT NULL DEFAULT 0,
    break_even_armed INTEGER NOT NULL DEFAULT 0,
    partial_done INTEGER NOT NULL DEFAULT 0,
    strategy TEXT,
    setup_key TEXT,
    opened_at TEXT,
    closed_at TEXT,
    last_event_at TEXT NOT NULL,
    last_action TEXT,
    client_order_id TEXT,
    payload TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS paper_order_state (
    client_order_id TEXT PRIMARY KEY,
    symbol TEXT NOT NULL,
    side TEXT NOT NULL,
    type TEXT NOT NULL,
    qty REAL NOT NULL DEFAULT 0,
    limit_price REAL,
    stop_price REAL,
    time_in_force TEXT NOT NULL DEFAULT 'day',
    status TEXT NOT NULL,
    submitted INTEGER NOT NULL DEFAULT 0,
    order_id TEXT,
    source_report TEXT,
    planned_at TEXT NOT NULL,
    submitted_at TEXT,
    updated_at TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_paper_order_state_symbol ON paper_order_state(symbol);
CREATE INDEX IF NOT EXISTS idx_paper_order_state_status ON paper_order_state(status);

CREATE TABLE IF NOT EXISTS paper_action_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    symbol TEXT,
    action TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'info',
    dry_run INTEGER NOT NULL DEFAULT 1,
    submitted INTEGER NOT NULL DEFAULT 0,
    order_id TEXT,
    client_order_id TEXT,
    reason TEXT,
    payload TEXT NOT NULL DEFAULT '{}'
);
SQL);
        $this->addColumnIfMissing('signals', 'direction', 'TEXT NOT NULL DEFAULT "long"');
        $this->addColumnIfMissing('signals', 'metadata', 'TEXT NOT NULL DEFAULT "{}"');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($columns as $row) {
            if (($row['name'] ?? '') === $column) {
                return;
            }
        }

        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    /** @param array<string, list<Bar>> $barsBySymbol */
    public function saveBars(array $barsBySymbol, string $timeframe): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO bars(symbol, timeframe, time, open, high, low, close, volume)
             VALUES(:symbol, :timeframe, :time, :open, :high, :low, :close, :volume)'
        );

        $this->pdo->beginTransaction();
        foreach ($barsBySymbol as $symbol => $bars) {
            foreach ($bars as $bar) {
                $stmt->execute([
                    ':symbol' => strtoupper($symbol),
                    ':timeframe' => $timeframe,
                    ':time' => $bar->time->format('Y-m-d'),
                    ':open' => $bar->open,
                    ':high' => $bar->high,
                    ':low' => $bar->low,
                    ':close' => $bar->close,
                    ':volume' => $bar->volume,
                ]);
            }
        }
        $this->pdo->commit();
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function loadBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bars
             WHERE symbol = :symbol AND timeframe = :timeframe AND time >= :start AND time <= :end
             ORDER BY time ASC'
        );

        $result = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            $stmt->execute([
                ':symbol' => $symbol,
                ':timeframe' => $timeframe,
                ':start' => $start,
                ':end' => $end,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result[$symbol] = array_map(static fn (array $row): Bar => new Bar(
                (string) $row['symbol'],
                new \DateTimeImmutable((string) $row['time']),
                (float) $row['open'],
                (float) $row['high'],
                (float) $row['low'],
                (float) $row['close'],
                (float) $row['volume'],
            ), $rows);
        }

        return $result;
    }

    /** @param list<Signal> $signals */
    public function saveSignals(array $signals): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO signals(symbol, strategy, created_at, entry, stop, target, risk_per_share, score, reasons, direction, metadata)
             VALUES(:symbol, :strategy, :created_at, :entry, :stop, :target, :risk_per_share, :score, :reasons, :direction, :metadata)'
        );

        $this->pdo->beginTransaction();
        foreach ($signals as $signal) {
            $stmt->execute([
                ':symbol' => $signal->symbol,
                ':strategy' => $signal->strategy,
                ':created_at' => $signal->createdAt->format('Y-m-d'),
                ':entry' => $signal->entry,
                ':stop' => $signal->stop,
                ':target' => $signal->target,
                ':risk_per_share' => $signal->riskPerShare,
                ':score' => $signal->score,
                ':reasons' => json_encode($signal->reasons, JSON_UNESCAPED_UNICODE),
                ':direction' => $signal->direction,
                ':metadata' => json_encode($signal->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $this->pdo->commit();
    }

    /** @param list<Trade> $trades */
    public function saveTrades(array $trades): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trades(symbol, strategy, entry_time, exit_time, entry, exit, shares, pnl, r_multiple, exit_reason, events)
             VALUES(:symbol, :strategy, :entry_time, :exit_time, :entry, :exit, :shares, :pnl, :r_multiple, :exit_reason, :events)'
        );

        $this->pdo->beginTransaction();
        foreach ($trades as $trade) {
            $stmt->execute([
                ':symbol' => $trade->symbol,
                ':strategy' => $trade->strategy,
                ':entry_time' => $trade->entryTime->format('Y-m-d'),
                ':exit_time' => $trade->exitTime->format('Y-m-d'),
                ':entry' => $trade->entry,
                ':exit' => $trade->exit,
                ':shares' => $trade->shares,
                ':pnl' => $trade->pnl,
                ':r_multiple' => $trade->rMultiple,
                ':exit_reason' => $trade->exitReason,
                ':events' => json_encode($trade->events, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $this->pdo->commit();
    }

    /**
     * @param list<array{captured_at:string, session_type:string, code:string, value:?float, payload:array<string, mixed>}> $metrics
     */
    public function saveDashboardMetrics(array $metrics): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dashboard_metrics(captured_at, session_type, code, value, payload)
             VALUES(:captured_at, :session_type, :code, :value, :payload)'
        );

        $this->pdo->beginTransaction();
        foreach ($metrics as $metric) {
            $stmt->execute([
                ':captured_at' => $metric['captured_at'],
                ':session_type' => $metric['session_type'],
                ':code' => strtoupper($metric['code']),
                ':value' => $metric['value'],
                ':payload' => json_encode($metric['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $this->pdo->commit();
    }

    /**
     * @param list<array{captured_at:string, symbol:string, timeframe:string, indicator:string, signal:?string, value:?float, payload:array<string, mixed>}> $snapshots
     */
    public function saveExternalIndicatorSnapshots(array $snapshots): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO external_indicator_snapshots(captured_at, symbol, timeframe, indicator, signal, value, payload)
             VALUES(:captured_at, :symbol, :timeframe, :indicator, :signal, :value, :payload)'
        );

        $this->pdo->beginTransaction();
        foreach ($snapshots as $snapshot) {
            $stmt->execute([
                ':captured_at' => $snapshot['captured_at'],
                ':symbol' => strtoupper($snapshot['symbol']),
                ':timeframe' => $snapshot['timeframe'],
                ':indicator' => strtolower($snapshot['indicator']),
                ':signal' => $snapshot['signal'],
                ':value' => $snapshot['value'],
                ':payload' => json_encode($snapshot['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $this->pdo->commit();
    }

    /** @return array<string, array<string, array<string, array<string, array{signal:?string, value:?float, payload:array<string, mixed>}>>>> */
    public function loadExternalIndicatorSnapshots(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM external_indicator_snapshots
             WHERE substr(captured_at, 1, 10) >= :start AND substr(captured_at, 1, 10) <= :end
             ORDER BY captured_at ASC'
        );
        $stmt->execute([':start' => $start, ':end' => $end]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $symbol = strtoupper((string) $row['symbol']);
            $date = substr((string) $row['captured_at'], 0, 10);
            $timeframe = strtoupper((string) $row['timeframe']);
            $indicator = strtolower((string) $row['indicator']);
            $payload = json_decode((string) $row['payload'], true);
            $result[$symbol][$date][$timeframe][$indicator] = [
                'signal' => $row['signal'] !== null ? (string) $row['signal'] : null,
                'value' => $row['value'] !== null ? (float) $row['value'] : null,
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    public function loadPaperPositionStates(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM paper_position_state ORDER BY symbol ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = [];
        foreach ($rows as $row) {
            $symbol = strtoupper((string) $row['symbol']);
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            $row['payload'] = is_array($payload) ? $payload : [];
            $row['break_even_armed'] = (bool) ((int) ($row['break_even_armed'] ?? 0));
            $row['partial_done'] = (bool) ((int) ($row['partial_done'] ?? 0));
            foreach (['qty', 'avg_entry_price', 'market_price', 'entry_price', 'stop_price', 'initial_stop_price', 'break_even_trigger_price', 'target_price'] as $numericColumn) {
                $row[$numericColumn] = (float) ($row[$numericColumn] ?? 0.0);
            }
            $result[$symbol] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $state */
    public function savePaperPositionState(array $state): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO paper_position_state(
                symbol, status, qty, avg_entry_price, market_price, entry_price, stop_price, initial_stop_price,
                break_even_trigger_price, target_price, break_even_armed, partial_done, strategy, setup_key,
                opened_at, closed_at, last_event_at, last_action, client_order_id, payload
             ) VALUES(
                :symbol, :status, :qty, :avg_entry_price, :market_price, :entry_price, :stop_price, :initial_stop_price,
                :break_even_trigger_price, :target_price, :break_even_armed, :partial_done, :strategy, :setup_key,
                :opened_at, :closed_at, :last_event_at, :last_action, :client_order_id, :payload
             )
             ON CONFLICT(symbol) DO UPDATE SET
                status = excluded.status,
                qty = excluded.qty,
                avg_entry_price = excluded.avg_entry_price,
                market_price = excluded.market_price,
                entry_price = excluded.entry_price,
                stop_price = excluded.stop_price,
                initial_stop_price = excluded.initial_stop_price,
                break_even_trigger_price = excluded.break_even_trigger_price,
                target_price = excluded.target_price,
                break_even_armed = excluded.break_even_armed,
                partial_done = excluded.partial_done,
                strategy = excluded.strategy,
                setup_key = excluded.setup_key,
                opened_at = COALESCE(paper_position_state.opened_at, excluded.opened_at),
                closed_at = excluded.closed_at,
                last_event_at = excluded.last_event_at,
                last_action = excluded.last_action,
                client_order_id = excluded.client_order_id,
                payload = excluded.payload'
        );

        $stmt->execute([
            ':symbol' => strtoupper((string) ($state['symbol'] ?? '')),
            ':status' => (string) ($state['status'] ?? 'unknown'),
            ':qty' => (float) ($state['qty'] ?? 0.0),
            ':avg_entry_price' => (float) ($state['avg_entry_price'] ?? 0.0),
            ':market_price' => (float) ($state['market_price'] ?? 0.0),
            ':entry_price' => (float) ($state['entry_price'] ?? 0.0),
            ':stop_price' => (float) ($state['stop_price'] ?? 0.0),
            ':initial_stop_price' => (float) ($state['initial_stop_price'] ?? 0.0),
            ':break_even_trigger_price' => (float) ($state['break_even_trigger_price'] ?? 0.0),
            ':target_price' => (float) ($state['target_price'] ?? 0.0),
            ':break_even_armed' => !empty($state['break_even_armed']) ? 1 : 0,
            ':partial_done' => !empty($state['partial_done']) ? 1 : 0,
            ':strategy' => $state['strategy'] ?? null,
            ':setup_key' => $state['setup_key'] ?? null,
            ':opened_at' => $state['opened_at'] ?? null,
            ':closed_at' => $state['closed_at'] ?? null,
            ':last_event_at' => (string) ($state['last_event_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)),
            ':last_action' => $state['last_action'] ?? null,
            ':client_order_id' => $state['client_order_id'] ?? null,
            ':payload' => json_encode($state['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    public function loadPaperOrderStates(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM paper_order_state ORDER BY updated_at DESC');
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = [];
        foreach ($rows as $row) {
            $clientOrderId = (string) ($row['client_order_id'] ?? '');
            if ($clientOrderId === '') {
                continue;
            }
            $result[$clientOrderId] = $this->normalizePaperOrderRow($row);
        }

        return $result;
    }

    /** @param array<string, mixed> $state */
    public function savePaperOrderState(array $state): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $stmt = $this->pdo->prepare(
            'INSERT INTO paper_order_state(
                client_order_id, symbol, side, type, qty, limit_price, stop_price, time_in_force, status,
                submitted, order_id, source_report, planned_at, submitted_at, updated_at, payload
             ) VALUES(
                :client_order_id, :symbol, :side, :type, :qty, :limit_price, :stop_price, :time_in_force, :status,
                :submitted, :order_id, :source_report, :planned_at, :submitted_at, :updated_at, :payload
             )
             ON CONFLICT(client_order_id) DO UPDATE SET
                symbol = excluded.symbol,
                side = excluded.side,
                type = excluded.type,
                qty = excluded.qty,
                limit_price = excluded.limit_price,
                stop_price = excluded.stop_price,
                time_in_force = excluded.time_in_force,
                status = excluded.status,
                submitted = excluded.submitted,
                order_id = COALESCE(excluded.order_id, paper_order_state.order_id),
                source_report = excluded.source_report,
                planned_at = paper_order_state.planned_at,
                submitted_at = COALESCE(excluded.submitted_at, paper_order_state.submitted_at),
                updated_at = excluded.updated_at,
                payload = excluded.payload'
        );

        $stmt->execute([
            ':client_order_id' => (string) ($state['client_order_id'] ?? ''),
            ':symbol' => strtoupper((string) ($state['symbol'] ?? '')),
            ':side' => strtolower((string) ($state['side'] ?? 'buy')),
            ':type' => strtolower((string) ($state['type'] ?? 'limit')),
            ':qty' => (float) ($state['qty'] ?? 0.0),
            ':limit_price' => array_key_exists('limit_price', $state) && $state['limit_price'] !== null ? (float) $state['limit_price'] : null,
            ':stop_price' => array_key_exists('stop_price', $state) && $state['stop_price'] !== null ? (float) $state['stop_price'] : null,
            ':time_in_force' => (string) ($state['time_in_force'] ?? 'day'),
            ':status' => (string) ($state['status'] ?? 'planned'),
            ':submitted' => !empty($state['submitted']) ? 1 : 0,
            ':order_id' => $state['order_id'] ?? null,
            ':source_report' => $state['source_report'] ?? null,
            ':planned_at' => (string) ($state['planned_at'] ?? $now),
            ':submitted_at' => $state['submitted_at'] ?? null,
            ':updated_at' => (string) ($state['updated_at'] ?? $now),
            ':payload' => json_encode($state['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recentPaperOrders(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM paper_order_state ORDER BY updated_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizePaperOrderRow($row), $rows);
    }

    /** @param array<string, mixed> $row */
    public function logPaperAction(array $row): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO paper_action_log(created_at, symbol, action, severity, dry_run, submitted, order_id, client_order_id, reason, payload)
             VALUES(:created_at, :symbol, :action, :severity, :dry_run, :submitted, :order_id, :client_order_id, :reason, :payload)'
        );
        $stmt->execute([
            ':created_at' => (string) ($row['created_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)),
            ':symbol' => isset($row['symbol']) ? strtoupper((string) $row['symbol']) : null,
            ':action' => (string) ($row['action'] ?? 'unknown'),
            ':severity' => (string) ($row['severity'] ?? 'info'),
            ':dry_run' => !empty($row['dry_run']) ? 1 : 0,
            ':submitted' => !empty($row['submitted']) ? 1 : 0,
            ':order_id' => $row['order_id'] ?? null,
            ':client_order_id' => $row['client_order_id'] ?? null,
            ':reason' => $row['reason'] ?? null,
            ':payload' => json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function recentPaperActions(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM paper_action_log ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            $row['payload'] = is_array($payload) ? $payload : [];
            $row['dry_run'] = (bool) ((int) ($row['dry_run'] ?? 0));
            $row['submitted'] = (bool) ((int) ($row['submitted'] ?? 0));

            return $row;
        }, $rows);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizePaperOrderRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $row['submitted'] = (bool) ((int) ($row['submitted'] ?? 0));
        foreach (['qty', 'limit_price', 'stop_price'] as $numericColumn) {
            $row[$numericColumn] = $row[$numericColumn] !== null ? (float) $row[$numericColumn] : null;
        }

        return $row;
    }
}
