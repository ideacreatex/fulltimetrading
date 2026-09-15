<?php
declare(strict_types=1);

namespace FulltimeTrading\Notifications;

/** Independent notification ledger; never opens the trading database. */
final class PaperCommentaryOutbox
{
    public function __construct(private readonly \PDO $db)
    {
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS commentary_outbox (notification_key TEXT PRIMARY KEY, message TEXT NOT NULL, message_sha256 TEXT NOT NULL,
            status TEXT NOT NULL, attempted_at TEXT NOT NULL, delivered_at TEXT, message_id INTEGER, error TEXT)');
    }

    public function receipt(string $key): ?array
    {
        $q = $this->db->prepare('SELECT notification_key,status,attempted_at,delivered_at,message_id,error FROM commentary_outbox WHERE notification_key=?');
        $q->execute([$key]); return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function deliver(string $key, string $message, callable $sender): array
    {
        $q = $this->db->prepare("INSERT OR IGNORE INTO commentary_outbox (notification_key,message,message_sha256,status,attempted_at) VALUES (?,?,?,'sending',?)");
        $q->execute([$key, $message, hash('sha256', $message), gmdate(DATE_ATOM)]);
        if ($q->rowCount() === 0) { return $this->receipt($key); }
        try {
            $response = $sender($message); $id = $response['result']['message_id'] ?? null;
            if (($response['ok'] ?? null) !== true || (!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id <= 0) { throw new \RuntimeException('Delivery not acknowledged.'); }
            $q = $this->db->prepare("UPDATE commentary_outbox SET status='delivered',delivered_at=?,message_id=? WHERE notification_key=? AND status='sending'");
            $q->execute([gmdate(DATE_ATOM), (int) $id, $key]);
        } catch (\Throwable $e) {
            // A timeout may follow successful delivery. Do not automatically duplicate it.
            $q = $this->db->prepare("UPDATE commentary_outbox SET status='uncertain',error=? WHERE notification_key=? AND status='sending'");
            $q->execute([get_class($e) . ': delivery outcome unknown; inspect before retry', $key]);
            throw new \RuntimeException('Commentary delivery uncertain; automatic retry disabled.', 0, $e);
        }
        return $this->receipt($key);
    }
}
