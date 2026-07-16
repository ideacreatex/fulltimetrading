<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * Resolves the validity of a paper-shadow target. This class is read-only and
 * deliberately has no order-submission dependency.
 */
final readonly class TacticalRotationShadowContext
{
    /** @var (\Closure(string,string):array)|null */
    private ?\Closure $calendar;

    /** @var (\Closure(string):array)|null */
    private ?\Closure $asset;

    public function __construct(?callable $calendar = null, ?callable $asset = null)
    {
        $this->calendar = $calendar === null ? null : \Closure::fromCallable($calendar);
        $this->asset = $asset === null ? null : \Closure::fromCallable($asset);
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    public function resolve(array $target, \DateTimeImmutable $nowNewYork, bool $validationSelected): array
    {
        $action = (string) ($target['action'] ?? 'hold');
        if (($target['rebalance_due_next_session'] ?? false) !== true
            || in_array($action, ['hold', 'hold_cash'], true)) {
            return $this->blocked('hold_no_trade');
        }
        if (!$validationSelected) {
            return $this->blocked('blocked_validation_failed');
        }
        $signalDate = (string) ($target['signal_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signalDate)) {
            return $this->blocked('blocked_invalid_signal_date');
        }
        if (!$this->calendar instanceof \Closure) {
            return $this->blocked('blocked_calendar_unavailable');
        }

        try {
            $start = (new \DateTimeImmutable($signalDate))->modify('+1 day')->format('Y-m-d');
            $end = (new \DateTimeImmutable($signalDate))->modify('+14 days')->format('Y-m-d');
            $sessions = ($this->calendar)($start, $end);
            $session = null;
            foreach ($sessions as $row) {
                if (is_array($row) && (string) ($row['date'] ?? '') > $signalDate) {
                    $session = $row;
                    break;
                }
            }
            if (!is_array($session)) {
                return $this->blocked('blocked_next_session_unknown');
            }
            $sessionDate = (string) $session['date'];
            $openClock = (string) ($session['open'] ?? '09:30');
            $openAt = new \DateTimeImmutable(
                $sessionDate . ' ' . $openClock,
                new \DateTimeZone('America/New_York'),
            );
            $status = $nowNewYork >= $openAt ? 'expired_no_chase' : 'ready_shadow';
            $assetCheck = null;
            $symbol = $target['symbol'] ?? null;
            if (is_string($symbol) && $symbol !== '') {
                if (!$this->asset instanceof \Closure) {
                    return $this->blocked('blocked_asset_check_unavailable');
                }
                $asset = ($this->asset)($symbol);
                $assetCheck = [
                    'symbol' => $symbol,
                    'status' => $asset['status'] ?? null,
                    'tradable' => (bool) ($asset['tradable'] ?? false),
                    'marginable' => (bool) ($asset['marginable'] ?? false),
                    'fractionable' => (bool) ($asset['fractionable'] ?? false),
                    'maintenance_margin_requirement' => isset($asset['maintenance_margin_requirement'])
                        ? (float) $asset['maintenance_margin_requirement']
                        : null,
                ];
                if ($assetCheck['status'] !== 'active' || $assetCheck['tradable'] !== true) {
                    $status = 'blocked_asset_not_tradable';
                } elseif ((float) ($target['gross'] ?? 0.0) > 1.0 && $assetCheck['marginable'] !== true) {
                    $status = 'blocked_asset_not_marginable';
                }
            }

            return [
                'status' => $status,
                'order_eligible' => false,
                'no_chase' => true,
                'valid_for_session' => $sessionDate,
                'expires_at' => $openAt->format(DATE_ATOM),
                'checked_at' => $nowNewYork->format(DATE_ATOM),
                'asset' => $assetCheck,
            ];
        } catch (\Throwable) {
            return $this->blocked('blocked_broker_check_failed');
        }
    }

    /** @return array{status:string,order_eligible:false,no_chase:true} */
    private function blocked(string $status): array
    {
        return ['status' => $status, 'order_eligible' => false, 'no_chase' => true];
    }
}
