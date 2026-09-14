<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** A new paper model inception, never a rewrite of historical qualification. */
final class TacticalPaperSignalEpoch
{
    public static function fromConfig(array $paper, string $asOf): ?array
    {
        if (!array_key_exists('signal_epoch', $paper)) {
            return null;
        }
        $epoch = $paper['signal_epoch'];
        if (!is_array($epoch) || ($paper['paper_only'] ?? null) !== true
            || ($epoch['mode'] ?? null) !== 'fresh_flat_paper_model'
            || ($epoch['run_id'] ?? null) !== ($paper['run_id'] ?? null)
            || !is_string($epoch['predecessor_run_id'] ?? null) || $epoch['predecessor_run_id'] === ''
            || $epoch['predecessor_run_id'] === $epoch['run_id']
            || !is_numeric($epoch['initial_equity'] ?? null)
            || !is_finite((float) $epoch['initial_equity']) || (float) $epoch['initial_equity'] <= 0.0) {
            throw new \RuntimeException('Invalid fresh paper signal epoch.');
        }
        $seed = $epoch['seed_close'] ?? '';
        $date = is_string($seed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $seed) : false;
        if ($date === false || $date->format('Y-m-d') !== $seed || $seed > $asOf) {
            throw new \RuntimeException('Paper signal epoch seed must be a completed close no later than the signal.');
        }
        return $epoch;
    }

    public static function assertActivationEquity(?array $epoch, float $actualEquity): void
    {
        if ($epoch !== null && (!is_finite($actualEquity)
            || abs((float) $epoch['initial_equity'] - $actualEquity) > 0.005)) {
            throw new \RuntimeException('Fresh paper epoch equity differs from the verified broker baseline.');
        }
    }
}
