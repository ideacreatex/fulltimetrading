<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

final class CandidateSession
{
    public static function resolve(array $calendar, array $clock, \DateTimeImmutable $now): array
    {
        if (!is_bool($clock['is_open'] ?? null) || !is_string($clock['timestamp'] ?? null)) { throw new \RuntimeException('Invalid broker clock.'); }
        $timestamp = new \DateTimeImmutable($clock['timestamp']);
        if (abs($now->getTimestamp() - $timestamp->getTimestamp()) > 60) { throw new \RuntimeException('Stale broker clock.'); }
        $zone = new \DateTimeZone('America/New_York'); $rows = [];
        foreach ($calendar as $row) {
            CandidateOrder::date($row['date'] ?? '');
            if (isset($rows[$row['date']]) || !preg_match('/^\d{2}:\d{2}$/D', $row['open'] ?? '') || !preg_match('/^\d{2}:\d{2}$/D', $row['close'] ?? '')) {
                throw new \RuntimeException('Invalid broker market calendar.');
            }
            $open = new \DateTimeImmutable($row['date'] . ' ' . $row['open'], $zone);
            $close = new \DateTimeImmutable($row['date'] . ' ' . $row['close'], $zone);
            if ($close <= $open) { throw new \RuntimeException('Invalid market-session boundaries.'); }
            $rows[$row['date']] = ['open' => $open, 'close' => $close];
        }
        ksort($rows); $completed = null; $next = null; $currentOpen = false;
        foreach ($rows as $date => $row) {
            if ($row['close']->getTimestamp() + 1200 <= $now->getTimestamp()) { $completed = $date; }
            if ($row['open'] <= $now && $now < $row['close']) { $currentOpen = true; }
        }
        if ($completed === null || $currentOpen !== $clock['is_open']) { throw new \RuntimeException('Broker clock/calendar inconsistent.'); }
        foreach ($rows as $date => $row) { if ($date > $completed) { $next = $date; break; } }
        if ($next === null) { throw new \RuntimeException('Next official market session is unavailable.'); }
        return ['signal_date' => $completed, 'scheduled_session' => $next, 'market_open' => $currentOpen,
            'signal_closed_at' => $rows[$completed]['close']->format(DATE_ATOM),
            'scheduled_close' => $rows[$next]['close']->format(DATE_ATOM), 'broker_timestamp' => $timestamp->format(DATE_ATOM)];
    }
}
