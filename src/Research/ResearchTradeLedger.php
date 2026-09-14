<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Exposure round trips, not broker fills. Accounting and simulated costs are not changed. */
final class ResearchTradeLedger
{
    public static function fromSleeveCurves(array $curves): array
    {
        if ($curves === [] || reset($curves) === []) { throw new \InvalidArgumentException('Nonempty sleeve curves required.'); }
        $reference = array_values(reset($curves));
        foreach ($curves as $name => $rows) {
            $curves[$name] = array_values($rows);
            if (count($rows) !== count($reference)) { throw new \RuntimeException('Sleeve lengths differ.'); }
        }
        $holdings = array_fill_keys(array_keys($curves), null);
        $activeBooks = $activePortfolio = $closedBooks = $closedPortfolio = [];
        $entriesBooks = $entriesPortfolio = 0;
        $years = [];
        $advance = static function (array $next, string $date, string $phase) use (&$holdings, &$activeBooks, &$activePortfolio,
            &$closedBooks, &$closedPortfolio, &$entriesBooks, &$entriesPortfolio): void {
            $beforeSymbols = array_fill_keys(array_values(array_filter($holdings, static fn ($s): bool => $s !== null)), true);
            $afterSymbols = array_fill_keys(array_values(array_filter($next, static fn ($s): bool => $s !== null)), true);
            foreach ($holdings as $name => $before) {
                $after = $next[$name];
                if ($before === $after) { continue; }
                if ($before !== null) {
                    $closedBooks[] = $activeBooks[$name] + ['exit_date' => $date, 'exit_phase' => $phase];
                    unset($activeBooks[$name]);
                }
                if ($after !== null) {
                    $activeBooks[$name] = ['sleeve' => $name, 'symbol' => $after, 'entry_date' => $date, 'entry_phase' => $phase];
                    $entriesBooks++;
                }
            }
            // Simultaneous open-boundary sleeve changes are one aggregate exposure transition.
            foreach (array_diff_key($beforeSymbols, $afterSymbols) as $symbol => $_) {
                $closedPortfolio[] = $activePortfolio[$symbol] + ['exit_date' => $date, 'exit_phase' => $phase];
                unset($activePortfolio[$symbol]);
            }
            foreach (array_diff_key($afterSymbols, $beforeSymbols) as $symbol => $_) {
                $activePortfolio[$symbol] = ['symbol' => $symbol, 'entry_date' => $date, 'entry_phase' => $phase];
                $entriesPortfolio++;
            }
            $holdings = $next;
        };
        $previousDate = null;
        foreach ($reference as $i => $referenceRow) {
            $date = $referenceRow['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($previousDate !== null && $date <= $previousDate)) {
                throw new \RuntimeException('Ledger dates must increase.');
            }
            $previousDate = $date;
            $years[substr($date, 0, 4)] = 0;
            $atOpen = $atClose = [];
            foreach ($curves as $name => $rows) {
                $row = $rows[$i];
                if (($row['date'] ?? null) !== $date || !array_key_exists('holding', $row)) { throw new \RuntimeException('Invalid sleeve exposure row.'); }
                $closing = $row['holding'];
                if ($closing !== null && (!is_string($closing) || $closing === '')) { throw new \RuntimeException('Invalid holding.'); }
                $stop = $row['standing_stop_event'] ?? null;
                if ($i === 0 && ($closing !== null || $stop !== null)) { throw new \RuntimeException('Trade ledger requires a flat inception.'); }
                $atClose[$name] = $closing;
                $atOpen[$name] = $closing;
                if ($stop !== null) {
                    if (!is_array($stop) || ($stop['date'] ?? null) !== $date || $closing !== null
                        || !is_string($stop['symbol'] ?? null) || $stop['symbol'] === '') { throw new \RuntimeException('Invalid stop exposure.'); }
                    if ($stop['kind'] === 'gap_open') {
                        if ($holdings[$name] !== $stop['symbol']) { throw new \RuntimeException('Gap stop has no matching overnight position.'); }
                        $atOpen[$name] = null;
                    } elseif (in_array($stop['kind'], ['stop_level', 'daily_low'], true)) {
                        $atOpen[$name] = $stop['symbol'];
                    } else { throw new \RuntimeException('Unknown stop execution phase.'); }
                }
            }
            $advance($atOpen, $date, 'open');
            $advance($atClose, $date, 'intraday_stop');
        }
        $portfolioAnnual = $sleeveAnnual = $years;
        foreach ($closedPortfolio as $trade) { $portfolioAnnual[substr($trade['exit_date'], 0, 4)]++; }
        foreach ($closedBooks as $trade) { $sleeveAnnual[substr($trade['exit_date'], 0, 4)]++; }
        if ($entriesPortfolio !== count($closedPortfolio) + count($activePortfolio)
            || $entriesBooks !== count($closedBooks) + count($activeBooks)) { throw new \RuntimeException('Trade conservation failed.'); }
        return ['definition' => 'One completed aggregate ticker exposure, attributed to exit year. No resize/partial-exit counts; no forced close at year-end or sample-end.',
            'phase_convention' => 'All sleeve rebalances at the same open are observed atomically; a ticker carried by any sleeve remains one position. This is not a broker order count.',
            'portfolio' => ['annual_closed' => $portfolioAnnual, 'closed_total' => count($closedPortfolio), 'opened_total' => $entriesPortfolio,
                'open_at_end' => array_values($activePortfolio), 'closed' => $closedPortfolio],
            'sleeves' => ['annual_closed' => $sleeveAnnual, 'closed_total' => count($closedBooks), 'opened_total' => $entriesBooks,
                'open_at_end' => array_values($activeBooks), 'closed' => $closedBooks]];
    }
}
