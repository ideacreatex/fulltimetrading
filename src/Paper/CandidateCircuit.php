<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Research\PortfolioCircuitController;

/** Rehydrates the frozen circuit from immutable completed-close observations, not process memory. */
final readonly class CandidateCircuit
{
    public function __construct(private array $config)
    {
        new PortfolioCircuitController($config);
    }

    public function advance(?array $state, string $date, array $rows, bool $confirmation): array
    {
        CandidateOrder::date($date);
        $state ??= ['config' => $this->config, 'observations' => []];
        if (CandidateOrder::json($state['config'] ?? null) !== CandidateOrder::json($this->config)
            || !is_array($state['observations'] ?? null)) { throw new \RuntimeException('Persistent circuit identity drift.'); }
        $this->validateRows($date, $rows);
        $observation = ['rows' => $rows, 'confirmation' => $confirmation];
        $history = $state['observations'];
        if (isset($history[$date])) {
            if (array_key_last($history) !== $date || CandidateOrder::json($history[$date]) !== CandidateOrder::json($observation)) {
                throw new \RuntimeException('Persistent circuit close revision.');
            }
        } else {
            if ($history !== [] && $date <= array_key_last($history)) { throw new \RuntimeException('Circuit session regression.'); }
            $history[$date] = $observation;
        }
        $confirmations = array_map(static fn ($o): bool => $o['confirmation'], $history);
        $controller = new PortfolioCircuitController($this->config, $confirmations);
        $feedback = null;
        foreach ($history as $session => $o) {
            $this->validateRows($session, $o['rows']);
            $feedback = $controller($session, $o['rows']);
        }
        return ['state' => ['config' => $this->config, 'observations' => $history], 'feedback' => $feedback, 'report' => $controller->report()];
    }

    private function validateRows(string $date, array $rows): void
    {
        if (count($rows) !== 12) { throw new \RuntimeException('Circuit requires all twelve capital books.'); }
        foreach ($rows as $row) {
            if (($row['date'] ?? null) !== $date) { throw new \RuntimeException('Circuit cross-sleeve date mismatch.'); }
            foreach (['equity', 'equity_low', 'start_equity'] as $field) {
                if (!is_numeric($row[$field] ?? null) || !is_finite((float) $row[$field]) || $row[$field] <= 0) {
                    throw new \RuntimeException('Invalid persistent circuit equity.');
                }
            }
            if ($row['equity_low'] > $row['equity'] + 1.0e-8) { throw new \RuntimeException('Circuit low exceeds close.'); }
        }
    }
}
