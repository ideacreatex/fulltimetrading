<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Immutable, whole-share paper order contract, distinct from the v4 market-only contract. */
final class CandidateOrder
{
    public const CONTRACT = 'candidate-whole-close-stop-v1';
    public const TERMINAL = ['filled', 'canceled', 'expired', 'rejected'];

    public static function make(
        string $run, string $sleeve, string $signalDate, string $session, string $epoch,
        string $purpose, string $symbol, int $quantity, string $tif = 'opg', ?float $stop = null,
    ): array {
        foreach ([$run, $sleeve, $epoch] as $id) {
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $id)) { throw new \InvalidArgumentException('Invalid candidate identity.'); }
        }
        self::date($signalDate); self::date($session);
        if ($session < $signalDate || !preg_match('/^[A-Z][A-Z0-9.-]{0,14}$/D', $symbol)
            || $quantity < 1 || $quantity > 100000000
            || !in_array($purpose, ['entry', 'rebalance_exit', 'protective_stop', 'circuit_exit'], true)) {
            throw new \InvalidArgumentException('Invalid candidate order.');
        }
        $protective = $purpose === 'protective_stop';
        if (($protective && ($tif !== 'gtc' || $stop === null || !is_finite($stop) || $stop <= 0))
            || (!$protective && ($stop !== null || !in_array($tif, ['opg', 'day'], true)))) {
            throw new \InvalidArgumentException('Invalid candidate order type/time in force.');
        }
        $body = ['symbol' => $symbol, 'qty' => (string) $quantity, 'side' => $purpose === 'entry' ? 'buy' : 'sell',
            'type' => $protective ? 'stop' : 'market', 'time_in_force' => $tif, 'extended_hours' => false];
        if ($protective) {
            $body['stop_price'] = self::stopPrice($stop);
        }
        $identity = ['contract' => self::CONTRACT, 'run' => $run, 'sleeve' => $sleeve,
            'signal_date' => $signalDate, 'session' => $session, 'epoch' => $epoch, 'purpose' => $purpose, 'body' => $body];
        $decision = hash('sha256', self::json($identity));
        $body['client_order_id'] = 'ftt5-' . substr($decision, 0, 40);
        return ['decision_id' => $decision, 'epoch_key' => hash('sha256', self::json([$run, $sleeve, $epoch, $purpose])),
            'run_id' => $run, 'sleeve_id' => $sleeve, 'signal_date' => $signalDate, 'scheduled_session' => $session,
            'leg' => $purpose, 'symbol' => $symbol, 'side' => $body['side'], 'requested_qty' => $quantity,
            'client_order_id' => $body['client_order_id'], 'payload' => ['contract' => self::CONTRACT,
                'identity' => $identity, 'body' => $body, 'time_in_force' => $tif]];
    }

    public static function assert(array $intent): void
    {
        $i = $intent['payload']['identity'] ?? [];
        $body = $i['body'] ?? [];
        $quantity = self::quantity($body['qty'] ?? null);
        $expected = self::make((string) ($i['run'] ?? ''), (string) ($i['sleeve'] ?? ''),
            (string) ($i['signal_date'] ?? ''), (string) ($i['session'] ?? ''), (string) ($i['epoch'] ?? ''),
            (string) ($i['purpose'] ?? ''), (string) ($body['symbol'] ?? ''), $quantity,
            (string) ($body['time_in_force'] ?? ''), isset($body['stop_price']) ? (float) $body['stop_price'] : null);
        foreach ($expected as $key => $value) {
            $actual = $intent[$key] ?? null;
            if ($key === 'requested_qty') { $actual = self::quantity($actual); }
            if ($key === 'payload') {
                // Operational metadata may be added, but execution identity/body may never change.
                foreach ($value as $field => $v) {
                    if (self::json($actual[$field] ?? null) !== self::json($v)) { throw new \RuntimeException('Candidate payload identity drift: ' . $field); }
                }
            } elseif ($actual !== $value) { throw new \RuntimeException('Candidate intent identity drift: ' . $key); }
        }
    }

    public static function quantity(mixed $value, bool $allowZero = false): int
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < ($allowZero ? 0 : 1)
            || (float) $value > 100000000 || abs((float) $value - round((float) $value)) > 1.0e-8) {
            throw new \RuntimeException('Candidate requires finite whole-share quantities.');
        }
        return (int) round((float) $value);
    }

    public static function stopPrice(float $price): string
    {
        if (!is_finite($price) || $price <= 0) { throw new \InvalidArgumentException('Invalid stop price.'); }
        $places = $price >= 1 ? 2 : 4; $scale = 10 ** $places;
        $rounded = floor($price * $scale + 1.0e-8) / $scale;
        if ($rounded <= 0) { throw new \InvalidArgumentException('Stop below broker price increment.'); }
        return number_format($rounded, $places, '.', '');
    }

    public static function date(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('Invalid candidate session.'); }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
