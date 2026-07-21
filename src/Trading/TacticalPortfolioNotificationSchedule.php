<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** Broker-clock based, account-scoped scheduling for durable portfolio reports. */
final class TacticalPortfolioNotificationSchedule
{
    public const OPEN_REPORT_AFTER = '09:35';
    private const CLOCK_MAX_DRIFT_SECONDS = 300;

    /**
     * @param array<string,mixed> $clock
     * @param array<string,mixed> $account
     * @param array<string,mixed> $signal
     * @return array{key:string,required_key:string,session_date:string,broker_timestamp:string,catch_up:bool}|null
     */
    public static function openStatus(
        array $clock,
        array $account,
        ?\DateTimeImmutable $hostNow = null,
        string $after = self::OPEN_REPORT_AFTER,
        array $signal = [],
    ): ?array {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $after) !== 1) {
            return null;
        }

        $brokerTime = self::freshBrokerTime($clock, $hostNow);
        $accountScope = self::accountScope($account);
        if ($brokerTime === null || $accountScope === null || $brokerTime->format('H:i') < $after) {
            return null;
        }

        $sessionDate = $brokerTime->format('Y-m-d');
        $marketOpen = ($clock['is_open'] ?? null) === true;
        if (!$marketOpen) {
            $asOf = trim((string) ($signal['as_of'] ?? ''));
            $intended = trim((string) ($signal['intended_session'] ?? ''));
            if ($asOf !== $sessionDate && $intended !== $sessionDate) {
                return null;
            }
        }
        $key = self::openKey($accountScope, $sessionDate);

        return [
            'key' => $key,
            'required_key' => $key,
            'session_date' => $sessionDate,
            'broker_timestamp' => $brokerTime->format(\DateTimeInterface::ATOM),
            'catch_up' => !$marketOpen || $brokerTime->format('H:i') > '10:00',
        ];
    }

    /**
     * Keep a missed opening report visible to health after the bell, including
     * after the close artifact changes intended_session to tomorrow.
     *
     * @param array<string,mixed> $clock
     * @param array<string,mixed> $account
     * @param array<string,mixed> $signal
     */
    public static function requiredOpenKey(
        array $clock,
        array $account,
        array $signal,
        ?\DateTimeImmutable $hostNow = null,
        string $after = self::OPEN_REPORT_AFTER,
    ): ?string {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $after) !== 1) {
            return null;
        }
        $brokerTime = self::freshBrokerTime($clock, $hostNow);
        $accountScope = self::accountScope($account);
        if ($brokerTime === null || $accountScope === null || $brokerTime->format('H:i') < $after) {
            return null;
        }

        $brokerDate = $brokerTime->format('Y-m-d');
        $asOf = trim((string) ($signal['as_of'] ?? ''));
        $intended = trim((string) ($signal['intended_session'] ?? ''));
        if ($asOf === $brokerDate || $intended === $brokerDate) {
            return self::openKey($accountScope, $brokerDate);
        }

        return null;
    }

    /**
     * A close report becomes eligible only after a validated artifact exists.
     * Its decision hash prevents an old compact `signal:` row from suppressing
     * the detailed report and allows a corrected decision to be delivered.
     *
     * @param array<string,mixed> $clock
     * @param array<string,mixed> $account
     * @param array<string,mixed> $signal
     * @return array{key:string,session_date:string,broker_timestamp:string,catch_up:bool}|null
     */
    public static function closeStatus(
        array $clock,
        array $account,
        array $signal,
        ?\DateTimeImmutable $hostNow = null,
    ): ?array {
        $brokerTime = self::freshBrokerTime($clock, $hostNow);
        $accountScope = self::accountScope($account);
        $asOf = trim((string) ($signal['as_of'] ?? ''));
        $decisionHash = strtolower(trim((string) ($signal['decision_sha256'] ?? '')));
        if ($brokerTime === null
            || $accountScope === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOf) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $decisionHash) !== 1
            || $asOf > $brokerTime->format('Y-m-d')) {
            return null;
        }

        // A same-date close artifact cannot be valid while that session is
        // still open. Prior-date artifacts may catch up on the next session.
        if ($asOf === $brokerTime->format('Y-m-d') && ($clock['is_open'] ?? null) === true) {
            return null;
        }

        return [
            'key' => sprintf('portfolio-close:%s:%s:%s:v3', $accountScope, $asOf, $decisionHash),
            'session_date' => $asOf,
            'broker_timestamp' => $brokerTime->format(\DateTimeInterface::ATOM),
            'catch_up' => $asOf < $brokerTime->format('Y-m-d') || $brokerTime->format('H:i') > '17:00',
        ];
    }

    /** @param array<string,mixed> $clock */
    private static function freshBrokerTime(
        array $clock,
        ?\DateTimeImmutable $hostNow,
    ): ?\DateTimeImmutable {
        $timestamp = trim((string) ($clock['timestamp'] ?? ''));
        if ($timestamp === '') {
            return null;
        }
        try {
            $timezone = new \DateTimeZone('America/New_York');
            $brokerTime = (new \DateTimeImmutable($timestamp))->setTimezone($timezone);
            $hostNow = ($hostNow ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
        if (abs($brokerTime->getTimestamp() - $hostNow->getTimestamp()) > self::CLOCK_MAX_DRIFT_SECONDS) {
            return null;
        }

        return $brokerTime;
    }

    /** @param array<string,mixed> $account */
    private static function accountScope(array $account): ?string
    {
        $reference = trim((string) ($account['id'] ?? $account['account_number'] ?? ''));

        return $reference !== '' ? substr(hash('sha256', $reference), 0, 12) : null;
    }

    private static function openKey(string $accountScope, string $sessionDate): string
    {
        return sprintf('portfolio-open:%s:%s:v3', $accountScope, $sessionDate);
    }
}
