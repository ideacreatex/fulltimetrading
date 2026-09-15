<?php
declare(strict_types=1);

namespace FulltimeTrading\Notifications;

/** Informational reports are separate from trade signals and execution gates. */
final class PaperMarketCommentary
{
    public static function phase(array $calendar, array $clock, \DateTimeImmutable $now): ?array
    {
        if (!is_bool($clock['is_open'] ?? null) || !is_string($clock['timestamp'] ?? null)
            || abs((new \DateTimeImmutable($clock['timestamp']))->getTimestamp() - $now->getTimestamp()) > 60) {
            throw new \RuntimeException('Commentary needs a fresh broker clock.');
        }
        $zone = new \DateTimeZone('America/New_York'); $local = $now->setTimezone($zone); $date = $local->format('Y-m-d');
        $today = array_values(array_filter($calendar, static fn ($r): bool => ($r['date'] ?? null) === $date));
        if ($today === []) { if ($clock['is_open']) { throw new \RuntimeException('Calendar/clock mismatch.'); } return null; }
        if (count($today) !== 1 || !preg_match('/^\d{2}:\d{2}$/D', $today[0]['open'] ?? '') || !preg_match('/^\d{2}:\d{2}$/D', $today[0]['close'] ?? '')) {
            throw new \RuntimeException('Invalid market calendar.');
        }
        $open = new \DateTimeImmutable($date . ' ' . $today[0]['open'], $zone);
        $close = new \DateTimeImmutable($date . ' ' . $today[0]['close'], $zone);
        if ($close <= $open || ($local >= $open && $local < $close) !== $clock['is_open']) { throw new \RuntimeException('Calendar/clock mismatch.'); }
        if ($local >= $open->modify('-30 minutes') && $local <= $open->modify('-10 minutes')) {
            return ['phase' => 'pre_open', 'session_date' => $date];
        }
        if ($local >= $close->modify('+30 minutes')) { return ['phase' => 'close', 'session_date' => $date]; }
        return null;
    }

    public static function key(string $run, string $date, string $phase): string
    {
        // Keep old open receipts readable, but phase() no longer schedules post-open opinion messages.
        if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $run) || !in_array($phase, ['pre_open', 'open', 'close'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) { throw new \InvalidArgumentException('Invalid commentary identity.'); }
        return 'market-commentary:' . $run . ':' . $date . ':' . $phase . ':v1';
    }

    public static function freshnessWarnings(string $requiredDate, ?string $artifactDate, ?string $planDate): array
    {
        $warnings = [];
        foreach (['signal' => $artifactDate, 'plan' => $planDate] as $kind => $date) {
            if ($date !== $requiredDate) {
                $warnings[] = $kind . '_date_mismatch: required=' . $requiredDate . '; observed=' . ($date ?? 'missing');
            }
        }
        return $warnings;
    }

    public static function render(array $draft, array $context, \DateTimeImmutable $now): string
    {
        $at = new \DateTimeImmutable($context['captured_at'] ?? 'invalid'); $age = $now->getTimestamp() - $at->getTimestamp();
        if (($draft['schema'] ?? null) !== 'paper-market-commentary-v1' || ($context['paper_only'] ?? null) !== true
            || $age < -5 || $age > 900 || ($draft['phase'] ?? null) !== ($context['due']['phase'] ?? null)
            || ($draft['session_date'] ?? null) !== ($context['due']['session_date'] ?? null) || !isset($context['due']['phase'])) {
            throw new \RuntimeException('Stale or mismatched commentary context.');
        }
        self::key($context['run_id'], $draft['session_date'], $draft['phase']);
        foreach (['equity', 'cash'] as $field) {
            if (!is_numeric($context['account'][$field] ?? null) || !is_finite((float) $context['account'][$field])) { throw new \InvalidArgumentException('Invalid broker account observation.'); }
        }
        if (!is_array($context['positions'] ?? null) || !is_array($context['open_orders'] ?? null)) { throw new \InvalidArgumentException('Missing broker exposure observation.'); }
        $label = match ($draft['phase']) { 'pre_open' => 'до открытия', 'open' => 'после открытия', 'close' => 'после закрытия' };
        $lines = ['АНАЛИТИЧЕСКИЙ КОММЕНТАРИЙ | ' . $draft['session_date'] . ' | ' . $label,
            'Мнение ассистента. НЕ торговая команда и НЕ подтверждение сделки.', '',
            'Факты на ' . $at->setTimezone(new \DateTimeZone('America/New_York'))->format('H:i:s') . ' Нью-Йорк:',
            sprintf('Капитал $%.2f; деньги $%.2f; позиций %d; открытых заявок %d.', $context['account']['equity'], $context['account']['cash'],
                count($context['positions']), count($context['open_orders']))];
        foreach (['market' => 'Рынок', 'algorithm' => 'Наш алгоритм', 'watch' => 'За чем наблюдаю'] as $key => $label) {
            $text = $draft[$key] ?? null;
            if (!is_string($text) || trim($text) === '' || !preg_match('//u', $text) || strlen($text) > 900
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text)
                || preg_match('/АКЦИИ ОТКРЫТЫ|ФАКТИЧЕСКОЕ ИСПОЛНЕНИЕ/u', $text)) { throw new \InvalidArgumentException('Invalid commentary section: ' . $key); }
            $lines[] = ''; $lines[] = $label . ': ' . trim($text);
        }
        $sources = $draft['sources'] ?? [];
        if (!is_array($sources) || count($sources) > 3) { throw new \InvalidArgumentException('At most three sources.'); }
        foreach ($sources as $source) {
            if (!is_string($source['url'] ?? null) || !filter_var($source['url'], FILTER_VALIDATE_URL)
                || parse_url($source['url'], PHP_URL_SCHEME) !== 'https' || parse_url($source['url'], PHP_URL_USER) !== null
                || strlen($source['url']) > 350 || !is_string($source['label'] ?? null) || strlen($source['label']) > 100
                || strpbrk($source['label'], "\r\n") !== false) { throw new \InvalidArgumentException('Invalid source citation.'); }
            $lines[] = $source['label'] . ': ' . $source['url'];
        }
        $lines[] = ''; $lines[] = 'Сигналы, заявки и подтверждённые исполнения сообщаются отдельно. Стратегия, стопы и gates этим комментарием не меняются.';
        $message = implode("\n", $lines);
        // The existing notifier truncates by bytes; never let it cut Russian UTF-8.
        if (strlen($message) > 3800) { throw new \InvalidArgumentException('Commentary exceeds safe Telegram byte limit; shorten it.'); }
        return $message;
    }
}
