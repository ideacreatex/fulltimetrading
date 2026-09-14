<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Research\AlgorithmSignalPolicy;

/** Completed-close transition for an actual capital book; all risk state is serializable. */
final class CandidateSleeveState
{
    public static function advance(array $config, ?array $previous, array $observation, array $context, array $feedback): array
    {
        $date = (string) ($observation['date'] ?? ''); CandidateOrder::date($date);
        if (($context['date'] ?? null) !== $date || !is_bool($feedback['force_cash'] ?? null)
            || !is_numeric($feedback['scale'] ?? null) || !is_finite((float) $feedback['scale']) || $feedback['scale'] < 0 || $feedback['scale'] > 1) {
            throw new \RuntimeException('Invalid paper close context/feedback.');
        }
        $equity = $observation['equity'] ?? null;
        $weights = $observation['weights'] ?? null;
        if (!is_numeric($equity) || !is_finite((float) $equity) || $equity <= 0 || !is_array($weights) || count($weights) > 1
            || !is_bool($observation['execution_complete'] ?? null) || !is_bool($observation['stop_filled'] ?? null)) {
            throw new \RuntimeException('Invalid actual paper close observation.');
        }
        foreach ($weights as $symbol => $weight) {
            if (!is_string($symbol) || !is_numeric($weight) || !is_finite((float) $weight) || $weight <= 0
                || !is_numeric($context['closes'][$symbol] ?? null) || $context['closes'][$symbol] <= 0) {
                throw new \RuntimeException('Invalid paper held position.');
            }
        }
        $hash = hash('sha256', CandidateOrder::json(array_diff_key($config,
            array_flip(['external_daily_scale', 'opportunity_features', 'nominal_prices']))));
        $fingerprint = hash('sha256', CandidateOrder::json([$observation, $context, $feedback]));
        if ($previous !== null && ($previous['config_hash'] ?? null) !== $hash) { throw new \RuntimeException('Paper sleeve risk configuration drift.'); }
        if ($previous !== null && $date === $previous['date']) {
            if (($previous['observation_hash'] ?? null) !== $fingerprint) { throw new \RuntimeException('Paper sleeve close revision.'); }
            return $previous;
        }
        if ($previous !== null && ($date <= $previous['date'] || $context['previous_session'] !== $previous['date'])) {
            throw new \RuntimeException('Paper sleeve must observe every market close in order.');
        }
        $s = $previous ?? ['index' => -1, 'equity' => (float) $equity, 'symbol' => null,
            'drawdown_peak' => (float) $equity, 'drawdown_latched' => false, 'drawdown_rearm_pending' => false,
            'cooldown_left' => 0, 'risk_exit_pending' => false, 'position_peak_close' => null,
            'last_circuit_index' => null, 'reentry_ramp_until' => -1, 'early_release_next_open' => false];
        $s['index']++; $index = $s['index']; $current = array_key_first($weights);
        if ($index === 0 && $weights !== []) { throw new \RuntimeException('Candidate activation begins from a flat account, not adopted historical holdings.'); }
        if ($index > 0) {
            if ($s['cooldown_left'] > 0) {
                if ($s['early_release_next_open']) {
                    $s['cooldown_left'] = 1;
                    $s['reentry_ramp_until'] = $index + (int) $config['circuit_reentry_ramp_sessions'] - 1;
                }
                $s['cooldown_left']--;
                if ($s['cooldown_left'] === 0 && $s['drawdown_rearm_pending'] && $config['drawdown_rearm_after_cooldown']) {
                    $s['drawdown_peak'] = (float) $s['equity']; $s['drawdown_latched'] = false; $s['drawdown_rearm_pending'] = false;
                }
            }
            if ($observation['execution_complete']) { $s['risk_exit_pending'] = false; }
            if ($current !== $s['symbol']) {
                $s['position_peak_close'] = $current === null ? null
                    : ($context['previous_closes'][$current] ?? throw new \RuntimeException('Missing held-symbol previous close.'));
            }
            $s['position_peak_close'] = $current === null ? null
                : max((float) ($s['position_peak_close'] ?? $context['closes'][$current]), (float) $context['closes'][$current]);
            $s['drawdown_peak'] = max($s['drawdown_peak'], (float) $equity);
            if ($equity >= $s['drawdown_peak'] * (1 - 1.0e-12)) { $s['drawdown_latched'] = false; }
        }
        $risk = $observation['stop_filled'] ? 'standing_stop' : null;
        if ($current !== null) {
            $close = (float) $context['closes'][$current]; $peak = (float) $s['position_peak_close'];
            $static = (float) $config['position_trailing_close_pct'];
            $staticBreached = $static > 0 && $close < $peak * (1 - $static);
            $multiple = (float) $config['position_dynamic_trailing_daily_vol_multiple'];
            $vol = $context['volatility'][$current] ?? null;
            $dynamicBreached = false;
            if ($multiple > 0 && is_float($vol) && is_finite($vol) && $vol > 0) {
                $pct = max((float) $config['position_dynamic_trailing_min_pct'], min((float) $config['position_dynamic_trailing_max_pct'], $vol / sqrt(252.) * $multiple));
                $dynamicBreached = $close < $peak * (1 - $pct);
            }
            if ($staticBreached || $dynamicBreached) { $risk = 'position_trailing_close'; }
        }
        if ($index > 0 && !$s['drawdown_latched'] && $equity / max(.000001, $s['drawdown_peak']) - 1 <= -(float) $config['drawdown_kill_pct']) {
            $risk = 'portfolio_drawdown'; $s['drawdown_latched'] = true;
        }
        if ($risk !== null) {
            $s['risk_exit_pending'] = true;
            if ($risk === 'portfolio_drawdown') {
                $s['cooldown_left'] = max($s['cooldown_left'], (int) $config['drawdown_cooldown_sessions'] + 1);
                $s['drawdown_rearm_pending'] = true; $s['last_circuit_index'] = $index;
            } else { $s['cooldown_left'] = max($s['cooldown_left'], (int) $config['position_exit_cooldown_sessions'] + 1); }
        }
        $s['equity'] = (float) $equity; $s['symbol'] = $current; $s['date'] = $date;
        $s['risk_signal'] = $risk; $s['config_hash'] = $hash; $s['observation_hash'] = $fingerprint;
        $s['early_release_next_open'] = $s['cooldown_left'] > 1 && $s['drawdown_rearm_pending'] && $current === null
            && $s['last_circuit_index'] !== null && ($context['reentry_conditions_met'] ?? null) === true
            && $index + 1 - $s['last_circuit_index'] > (int) $config['circuit_reentry_min_sessions'];
        $due = $s['risk_exit_pending'] || $index % (int) $config['rebalance_sessions'] === (int) $config['rebalance_phase']
            || ($feedback['force_cash'] && $weights !== []);
        $nextCooldown = $s['early_release_next_open'] ? 0 : max(0, $s['cooldown_left'] - 1);
        $ranked = $context['desired'];
        $desired = $s['risk_exit_pending'] || $nextCooldown > 0 || $feedback['force_cash'] ? [] : $ranked;
        if ($feedback['scale'] !== 1.0) { $desired = array_map(static fn ($w): float => $w * $feedback['scale'], $desired); }
        if ($index + 1 <= $s['reentry_ramp_until'] || ($s['early_release_next_open'] && (int) $config['circuit_reentry_ramp_sessions'] > 0)) {
            $desired = array_map(static fn ($w): float => $w * (float) $config['circuit_reentry_initial_scale'], $desired);
        }
        $desired = AlgorithmSignalPolicy::executionTarget($weights, $desired, (float) $config['max_gross'], $config['algorithm_policy']);
        $symbol = $due ? array_key_first($desired) : null;
        $action = !$due ? 'hold' : ($symbol === null ? ($current === null ? 'hold_cash' : 'exit_to_cash')
            : ($symbol === $current ? 'resize_or_hold' : 'rebalance'));
        $s['target'] = ['signal_date' => $date, 'execution' => 'next_session_open', 'action' => $action,
            'rebalance_due_next_session' => $due, 'current_symbol' => $current, 'current_gross' => (float) array_sum($weights),
            'symbol' => $symbol, 'gross' => $due ? (float) array_sum($desired) : 0.,
            'ranked_symbol' => array_key_first($ranked), 'ranked_gross' => (float) array_sum($ranked),
            'circuit_cooldown_left' => $s['cooldown_left'], 'cooldown_after_next_open_tick' => $nextCooldown,
            'risk_exit_pending' => $s['risk_exit_pending'], 'drawdown_rearm_pending' => $s['drawdown_rearm_pending'],
            'shadow_only' => true];
        return $s;
    }
}
