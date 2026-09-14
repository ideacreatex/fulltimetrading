<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Domain\Bar;

/** Diagnostic only: minute OHLC is not NBBO election or an executable fill guarantee. */
final class RthStandingStopAudit
{
    public static function inspect(array $event, array $minutes, array $session): array
    {
        $zone = new \DateTimeZone('America/New_York');
        $open = new \DateTimeImmutable($event['date'] . ' ' . $session['open'], $zone);
        $close = new \DateTimeImmutable($event['date'] . ' ' . $session['close'], $zone);
        $bars = [];
        $previous = null;
        foreach ($minutes as $bar) {
            if (!$bar instanceof Bar || $bar->symbol !== $event['symbol'] || ($previous !== null && $bar->time <= $previous)) {
                throw new \RuntimeException('Wrong symbol or unordered minute history.');
            }
            $previous = $bar->time;
            if ($bar->time >= $open && $bar->time < $close) { $bars[] = $bar; }
        }
        $stop = (float) $event['stop'];
        if (!is_finite($stop) || $stop <= 0 || $bars === []) { throw new \RuntimeException('Invalid stop or missing RTH minute history.'); }
        $trigger = null;
        foreach ($bars as $i => $bar) { if ($bar->low <= $stop) { $trigger = $i; break; } }
        $next = $trigger !== null ? ($bars[$trigger + 1] ?? null) : null;
        $contiguous = $next !== null && $next->time->getTimestamp() - $bars[$trigger]->time->getTimestamp() === 60;
        return ['date' => $event['date'], 'symbol' => $event['symbol'], 'stop' => $stop, 'daily_fill' => $event['fill'],
            'daily_kind' => $event['kind'], 'minutes' => count($bars), 'opening_minute_present' => $bars[0]->time == $open,
            'rth_open' => $bars[0]->open, 'rth_low' => min(array_map(static fn (Bar $b): float => $b->low, $bars)),
            'rth_touch' => $trigger !== null, 'first_touch_at' => $trigger === null ? null : $bars[$trigger]->time->format(DATE_ATOM),
            'next_minute_contiguous' => $contiguous,
            'next_minute_open_proxy' => $contiguous ? $next->open : null,
            'next_minute_vs_daily_fill_bps' => $contiguous ? ($next->open / $event['fill'] - 1) * 10000 : null,
            'rth_open_vs_daily_fill_bps' => $event['kind'] === 'gap_open' ? ($bars[0]->open / $event['fill'] - 1) * 10000 : null];
    }
}
