<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

final class FixedShareExecutionEquityBuilder
{
    /**
     * Rebuild a daily mark-to-market path from independently replayed trades.
     * Historical share quantities remain fixed, but entries, partials and exits
     * are booked on their actual replay session instead of the daily model's
     * original exit session.
     *
     * @param list<string> $sessions
     * @param list<array<string, mixed>> $replays
     * @param array<string, array<string, float>> $sessionCloses symbol => date => close
     * @return list<array{date:string, equity:float}>
     */
    public function build(array $sessions, array $replays, array $sessionCloses, float $startingEquity): array
    {
        $sessions = array_values(array_unique(array_filter(
            array_map(static fn (mixed $date): string => substr((string) $date, 0, 10), $sessions),
            static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1,
        )));
        sort($sessions, SORT_STRING);
        $normalized = array_values(array_filter(array_map(
            fn (array $row): ?array => $this->normalizeReplay($row),
            array_values(array_filter($replays, 'is_array')),
        )));

        $curve = [];
        $lastMarks = [];
        foreach ($sessions as $date) {
            foreach ($sessionCloses as $symbol => $closes) {
                if (isset($closes[$date]) && (float) $closes[$date] > 0.0) {
                    $lastMarks[$symbol] = (float) $closes[$date];
                }
            }

            $equity = $startingEquity;
            foreach ($normalized as $trade) {
                if ($date < $trade['entry_date']) {
                    continue;
                }

                $equity -= $trade['entry_cost'];
                $remainingShares = $trade['shares'];
                if ($trade['partial_shares'] > 0.0 && $trade['partial_date'] !== null && $date >= $trade['partial_date']) {
                    $equity += ($trade['partial_price'] - $trade['entry']) * $trade['partial_shares'];
                    $equity -= $trade['partial_cost'];
                    $remainingShares = max(0.0, $remainingShares - $trade['partial_shares']);
                }

                if ($date >= $trade['exit_date']) {
                    $equity += ($trade['exit'] - $trade['entry']) * $remainingShares;
                    $equity -= $trade['exit_cost'];
                    continue;
                }

                $mark = (float) ($sessionCloses[$trade['symbol']][$date]
                    ?? $lastMarks[$trade['symbol']]
                    ?? $trade['entry']);
                $equity += ($mark - $trade['entry']) * $remainingShares;
            }

            $curve[] = ['date' => $date, 'equity' => $equity];
        }

        return $curve;
    }

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    private function normalizeReplay(array $row): ?array
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? ''));
        $entry = (float) ($row['minute_entry'] ?? 0.0);
        $exit = (float) ($row['minute_exit'] ?? 0.0);
        $shares = (float) ($row['shares'] ?? 0.0);
        $entryDate = $this->sessionDate($row['minute_entry_time'] ?? null, $row['daily_entry_date'] ?? null);
        $exitDate = $this->sessionDate($row['minute_exit_time'] ?? null, $row['daily_exit_date'] ?? null);
        if ($symbol === '' || $entry <= 0.0 || $exit <= 0.0 || $shares <= 0.0 || $entryDate === null || $exitDate === null) {
            return null;
        }

        $partialShares = max(0.0, min($shares, (float) ($row['minute_partial_shares'] ?? 0.0)));
        $partialDate = $partialShares > 0.0
            ? $this->sessionDate($row['minute_partial_time'] ?? null, null)
            : null;
        $partialPrice = (float) ($row['minute_partial_price'] ?? 0.0);
        if ($partialDate === null || $partialPrice <= 0.0) {
            $partialShares = 0.0;
            $partialDate = null;
            $partialPrice = 0.0;
        }

        return [
            'symbol' => $symbol,
            'entry' => $entry,
            'entry_date' => $entryDate,
            'exit' => $exit,
            'exit_date' => $exitDate,
            'shares' => $shares,
            'entry_cost' => max(0.0, (float) ($row['modeled_entry_cost'] ?? 0.0)),
            'partial_shares' => $partialShares,
            'partial_price' => $partialPrice,
            'partial_date' => $partialDate,
            'partial_cost' => max(0.0, (float) ($row['modeled_partial_costs'] ?? 0.0)),
            'exit_cost' => max(0.0, (float) ($row['modeled_exit_cost'] ?? 0.0)),
        ];
    }

    private function sessionDate(mixed $timestamp, mixed $fallback): ?string
    {
        $value = trim((string) $timestamp);
        if ($value !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $value;
            }
            try {
                return (new \DateTimeImmutable($value))
                    ->setTimezone(new \DateTimeZone('America/New_York'))
                    ->format('Y-m-d');
            } catch (\Throwable) {
                // Fall through to an explicit daily-session date.
            }
        }

        $date = substr(trim((string) $fallback), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }
}
