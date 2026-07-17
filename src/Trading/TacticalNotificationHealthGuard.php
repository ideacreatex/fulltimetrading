<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

use PDO;

/**
 * Read-only health assessment for the durable tactical Telegram outbox.
 *
 * Delivery is proven from the persisted outbox rather than from the latest
 * executor event list, because a restart legitimately deduplicates messages
 * that were already delivered by an earlier cycle.
 */
final class TacticalNotificationHealthGuard
{
    /**
     * @param ?array<string,mixed> $cycle
     * @return array{
     *   expected:bool,
     *   ok:bool,
     *   pending_count:int,
     *   failed_pending_count:int,
     *   delivered_count:int,
     *   last_delivered_at:?string,
     *   required:array{signal:bool,transition:bool,activation:bool},
     *   errors:list<string>
     * }
     */
    public static function assess(string $databasePath, string $runId, ?array $cycle): array
    {
        $empty = [
            'expected' => false,
            'ok' => true,
            'pending_count' => 0,
            'failed_pending_count' => 0,
            'delivered_count' => 0,
            'last_delivered_at' => null,
            'required' => ['signal' => false, 'transition' => false, 'activation' => false],
            'errors' => [],
        ];
        if ($runId === '' || !is_file($databasePath)) {
            return $empty;
        }

        try {
            $pdo = new PDO('sqlite:' . $databasePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA busy_timeout=5000');

            $runStatement = $pdo->prepare('SELECT status FROM tactical_paper_run WHERE run_id=:run_id');
            $runStatement->execute([':run_id' => $runId]);
            $run = $runStatement->fetch();
            if (!is_array($run)) {
                return $empty;
            }

            $summary = $pdo->query(
                'SELECT
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN delivered_at IS NULL AND attempt_count > 0 THEN 1 ELSE 0 END) AS failed_pending_count,
                    SUM(CASE WHEN delivered_at IS NOT NULL AND status = \'delivered\' THEN 1 ELSE 0 END) AS delivered_count,
                    MAX(delivered_at) AS last_delivered_at
                 FROM tactical_paper_notification'
            )->fetch();
            if (!is_array($summary)) {
                throw new \RuntimeException('Unable to read tactical notification summary.');
            }

            $pending = (int) ($summary['pending_count'] ?? 0);
            $failedPending = (int) ($summary['failed_pending_count'] ?? 0);
            $delivered = (int) ($summary['delivered_count'] ?? 0);
            $required = ['signal' => false, 'transition' => false, 'activation' => false];
            $errors = [];
            if ($pending > 0) {
                $errors[] = 'tactical_notification_backlog';
            }
            if ($failedPending > 0) {
                $errors[] = 'tactical_notification_delivery_failed';
            }

            if ($cycle !== null) {
                $signalAsOf = trim((string) ($cycle['signal']['as_of'] ?? ''));
                $required['signal'] = $signalAsOf !== '';
                if (!$required['signal'] || !self::deliveredWithPrefix($pdo, 'signal:' . $signalAsOf . ':')) {
                    $errors[] = 'tactical_notification_signal_missing';
                }

                $brokerPositions = is_array($cycle['broker']['positions'] ?? null)
                    ? $cycle['broker']['positions']
                    : [];
                $brokerOrders = is_array($cycle['broker']['open_orders'] ?? null)
                    ? $cycle['broker']['open_orders']
                    : [];
                $required['transition'] = (string) ($cycle['run_status'] ?? '') === 'transition'
                    && ($brokerPositions !== [] || $brokerOrders !== []);
                if ($required['transition'] && !self::deliveredWithPrefix($pdo, 'transition:')) {
                    $errors[] = 'tactical_notification_transition_missing';
                }

                $required['activation'] = str_starts_with(
                    (string) ($cycle['reconciliation_status'] ?? ''),
                    'activated_',
                );
                if ($required['activation'] && !self::deliveredWithPrefix($pdo, 'activated:')) {
                    $errors[] = 'tactical_notification_activation_missing';
                }
            }

            $errors = array_values(array_unique($errors));

            return [
                'expected' => true,
                'ok' => $errors === [],
                'pending_count' => $pending,
                'failed_pending_count' => $failedPending,
                'delivered_count' => $delivered,
                'last_delivered_at' => isset($summary['last_delivered_at'])
                    && is_string($summary['last_delivered_at'])
                    && $summary['last_delivered_at'] !== ''
                        ? $summary['last_delivered_at']
                        : null,
                'required' => $required,
                'errors' => $errors,
            ];
        } catch (\Throwable) {
            return [
                ...$empty,
                'expected' => true,
                'ok' => false,
                'errors' => ['tactical_notification_health_unavailable'],
            ];
        }
    }

    private static function deliveredWithPrefix(PDO $pdo, string $prefix): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM tactical_paper_notification
             WHERE delivered_at IS NOT NULL AND status=\'delivered\'
               AND substr(notification_key,1,:length)=:prefix
             LIMIT 1'
        );
        $statement->bindValue(':length', strlen($prefix), PDO::PARAM_INT);
        $statement->bindValue(':prefix', $prefix);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }
}
