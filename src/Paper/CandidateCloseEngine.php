<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Trading\WholeShareSizing;

final readonly class CandidateCloseEngine
{
    public function __construct(private CandidateLedger $ledger) { }

    /** Inputs must come from a validated completed-session data snapshot and reconciled account. */
    public function prepare(string $run, string $date, string $nextSession, array $books, array $contexts,
        array $nominalCloses, bool $confirmation, array $provenance): array
    {
        CandidateOrder::date($date); CandidateOrder::date($nextSession);
        if (count($books) !== 12 || array_diff_key($books, $contexts) !== [] || $nextSession <= $date) {
            throw new \RuntimeException('Invalid candidate close inputs.');
        }
        $existing = $this->ledger->checkpoint($run, 'close:' . $date);
        if ($existing !== null) {
            if ($existing['payload']['provenance'] !== $provenance || $existing['payload']['scheduled_session'] !== $nextSession) {
                throw new \RuntimeException('Frozen candidate close provenance drift.');
            }
            return ['already_committed' => true, 'payload' => $existing['payload']];
        }
        $sleeves = $this->ledger->views->sleeves($run); $positions = $this->ledger->views->positions($run);
        $actualNames = array_keys($sleeves); $expectedNames = array_keys($books); sort($actualNames); sort($expectedNames);
        if ($actualNames !== $expectedNames) { throw new \RuntimeException('Candidate close sleeve definition drift.'); }
        $rows = $observations = $prior = $updates = $versions = $notionals = [];
        foreach ($books as $name => $book) {
            if (abs($book['allocation'] - $sleeves[$name]['allocation']) > 1e-12) { throw new \RuntimeException('Candidate close allocation drift.'); }
            $versions[$name] = (int) $sleeves[$name]['version']; $notional = [];
            foreach ($positions[$name] ?? [] as $symbol => $position) {
                if (isset($position['updated_at']) && (new \DateTimeImmutable($position['updated_at']))
                    ->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d') > $date) {
                    throw new \RuntimeException('A later position observation cannot be backdated into this close.');
                }
                $qty = CandidateOrder::quantity($position['qty']); $price = $nominalCloses[$symbol] ?? null;
                if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0) { throw new \RuntimeException('Missing nominal held-symbol close.'); }
                $notional[$symbol] = $qty * $price;
            }
            if (count($notional) > 1) { throw new \RuntimeException('Candidate close cannot hide unfinished cross-symbol rotation.'); }
            $nav = (float) $sleeves[$name]['cash'] + array_sum($notional);
            if ($nav <= 0 || !is_finite($nav)) { throw new \RuntimeException('Candidate sleeve insolvency.'); }
            $prior[$name] = $this->ledger->checkpoint($run, 'sleeve:' . $name);
            $old = $prior[$name]['payload'] ?? null;
            $latch = $this->ledger->checkpoint($run, 'stop_latch:' . $name);
            $stopFilled = ($latch['payload']['pending'] ?? false) === true;
            if ($stopFilled) {
                $observedDate = (new \DateTimeImmutable($latch['payload']['observed_at']))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
                if ($observedDate > $date) { throw new \RuntimeException('A later fill cannot be backdated into this close.'); }
                $updates['stop_latch:' . $name] = ['version' => (int) $latch['version'],
                    'payload' => array_replace($latch['payload'], ['pending' => false, 'consumed_close' => $date])];
            }
            $observations[$name] = ['date' => $date, 'equity' => $nav,
                'weights' => array_map(static fn ($n): float => $n / $nav, $notional),
                'execution_complete' => !($old['risk_exit_pending'] ?? false) || $notional === [], 'stop_filled' => $stopFilled];
            $rows[$name] = ['date' => $date, 'start_equity' => (float) ($old['equity'] ?? $nav), 'equity' => $nav, 'equity_low' => $nav];
            if ($stopFilled) { $rows[$name]['standing_stop_event'] = ['observed' => true]; }
            $notionals[$name] = $notional;
        }
        $circuitCheckpoint = $this->ledger->checkpoint($run, 'circuit');
        $circuit = (new CandidateCircuit(CandidateDefinition::CIRCUIT))->advance($circuitCheckpoint['payload'] ?? null, $date, $rows, $confirmation);
        $updates['circuit'] = ['version' => (int) ($circuitCheckpoint['version'] ?? 0), 'payload' => $circuit['state']];
        $plans = [];
        foreach ($books as $name => $book) {
            $held = array_key_first($notionals[$name]); $context = $contexts[$name]($date, $held);
            $state = CandidateSleeveState::advance($book['config'], $prior[$name]['payload'] ?? null,
                $observations[$name], $context, $circuit['feedback']);
            $updates['sleeve:' . $name] = ['version' => (int) ($prior[$name]['version'] ?? 0), 'payload' => $state];
            $target = $state['target']; $quantities = null;
            if ($target['rebalance_due_next_session']) {
                $quantities = WholeShareSizing::target($state['equity'], $notionals[$name], $target['symbol'],
                    $target['gross'], $nominalCloses, (float) $book['config']['cost_bps']);
            }
            $plans[$name] = ['target' => $target, 'target_quantities' => $quantities, 'reference_nav' => $state['equity'],
                'reference_prices' => array_intersect_key($nominalCloses, array_flip(array_filter([$held, $target['symbol']]))),
                'circuit_feedback' => $circuit['feedback'], 'executable_permission' => false];
            if ($held !== null) {
                $scope = 'protection:' . $name . ':' . $held; $cp = $this->ledger->checkpoint($run, $scope); $p = $cp['payload'] ?? [];
                if (isset($p['last_close_date']) && $p['last_close_date'] > $date) { throw new \RuntimeException('Stop close regression.'); }
                $cost = $positions[$name][$held]['cost_basis'] / $positions[$name][$held]['qty'];
                $p['peak_close'] = max((float) ($p['peak_close'] ?? $cost), (float) $nominalCloses[$held]);
                $p['last_close_date'] = $date; $p['last_close'] = (float) $nominalCloses[$held];
                $updates[$scope] = ['version' => (int) ($cp['version'] ?? 0), 'payload' => $p];
            }
        }
        return ['already_committed' => false, 'run_id' => $run, 'date' => $date, 'scheduled_session' => $nextSession,
            'book_versions' => $versions, 'updates' => $updates, 'plans' => $plans, 'provenance' => $provenance,
            'account_close_nav' => array_sum(array_column($rows, 'equity'))];
    }

    public function commit(array $prepared): array
    {
        if ($prepared['already_committed']) { return $prepared['payload']; }
        return $this->ledger->commitClose($prepared['run_id'], $prepared['date'], $prepared['scheduled_session'],
            $prepared['book_versions'], $prepared['updates'], $prepared['plans'], $prepared['provenance']);
    }
}
