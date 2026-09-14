<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Research-only account-wide circuit. Risk epochs reset; reported lifetime equity does not. */
final class PortfolioCircuitController
{
    private float $peak = 0.0;
    private bool $paused = false;
    private int $elapsed = 0;
    private int $ramp = 0;
    private ?string $previousDate = null;
    private array $events = [];
    private array $history = [];
    private array $config;

    public function __construct(array $config = [], private readonly array $confirmation = [])
    {
        $this->config = array_replace(['drawdown' => 0.0, 'pause' => 10, 'minimum_pause' => 3, 'release' => 'fixed',
            'on_position_stop' => false, 'initial_scale' => 1.0, 'ramp_sessions' => 5, 'trigger_basis' => 'close'], $config);
        $c = $this->config;
        if (!is_numeric($c['drawdown']) || !is_finite((float) $c['drawdown']) || $c['drawdown'] < 0 || $c['drawdown'] >= 1
            || !is_int($c['pause']) || $c['pause'] < 1 || !is_int($c['minimum_pause']) || $c['minimum_pause'] < 1
            || !is_int($c['ramp_sessions']) || $c['ramp_sessions'] < 0 || !is_bool($c['on_position_stop'])
            || !in_array($c['release'], ['fixed', 'confirmed', 'either'], true) || !in_array($c['trigger_basis'], ['close', 'low'], true)
            || !is_numeric($c['initial_scale']) || !is_finite((float) $c['initial_scale']) || $c['initial_scale'] <= 0 || $c['initial_scale'] > 1) {
            throw new \InvalidArgumentException('Invalid shared portfolio circuit.');
        }
    }

    public function __invoke(string $date, array $rows): array
    {
        if ($rows === [] || ($this->previousDate !== null && $date <= $this->previousDate)) { throw new \RuntimeException('Circuit dates must increase.'); }
        $this->previousDate = $date;
        $equity = $start = $low = 0.0;
        $stops = 0;
        foreach ($rows as $row) {
            if ($row['date'] !== $date) { throw new \RuntimeException('Cross-sleeve signal mismatch.'); }
            $equity += $row['equity']; $start += $row['start_equity']; $low += $row['equity_low'];
            $stops += (int) isset($row['standing_stop_event']);
        }
        if (!is_finite($equity) || $equity <= 0) { throw new \RuntimeException('Invalid account equity.'); }
        $c = $this->config;
        if ($c['release'] !== 'fixed' && !is_bool($this->confirmation[$date] ?? null)) { throw new \RuntimeException('Missing causal confirmation: ' . $date); }
        $confirmed = $this->confirmation[$date] ?? false;
        $this->peak = max($this->peak, $start);
        if ($this->paused) {
            $this->elapsed++;
            $release = match ($c['release']) {
                'fixed' => $this->elapsed >= $c['pause'],
                'confirmed' => $this->elapsed >= $c['minimum_pause'] && $confirmed,
                'either' => $this->elapsed >= $c['pause'] || ($this->elapsed >= $c['minimum_pause'] && $confirmed),
            };
            if ($release) {
                $this->paused = false;
                $this->peak = $equity;
                $this->ramp = $c['ramp_sessions'];
                $this->events[] = ['date' => $date, 'event' => 'release_next_open', 'elapsed_cash_sessions' => $this->elapsed, 'confirmation' => $confirmed];
            }
        } else {
            $basis = $c['trigger_basis'] === 'low' ? $low : $equity;
            $breached = $c['drawdown'] > 0 && $basis / $this->peak - 1 <= -$c['drawdown'];
            if ($breached || ($c['on_position_stop'] && $stops > 0)) {
                $this->paused = true;
                $this->elapsed = 0;
                $this->events[] = ['date' => $date, 'event' => 'liquidate_next_open', 'reason' => $breached ? 'portfolio_drawdown' : 'position_stop',
                    'equity' => $equity, 'risk_epoch_peak' => $this->peak, 'trigger_value' => $basis];
            }
            $this->peak = max($this->peak, $equity);
        }
        $feedback = ['force_cash' => $this->paused, 'scale' => !$this->paused && $this->ramp > 0 ? (float) $c['initial_scale'] : 1.0];
        if (!$this->paused && $this->ramp > 0) { $this->ramp--; }
        $this->history[$date] = $feedback + ['elapsed' => $this->elapsed, 'equity' => $equity, 'risk_epoch_peak' => $this->peak];
        return $feedback;
    }

    public function report(): array { return ['config' => $this->config, 'events' => $this->events, 'history' => $this->history]; }
}
