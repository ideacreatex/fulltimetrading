<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final readonly class TacticalRotationExecutionWindow
{
    public function __construct(
        private string $preopenStart = '09:15',
        private string $preopenCutoff = '09:27',
        private string $eveningQueueStart = '19:05',
        private string $postopenRotationCutoff = '09:32',
        private string $riskExitDayCutoff = '15:45',
    ) {
        foreach ([
            $this->preopenStart,
            $this->preopenCutoff,
            $this->eveningQueueStart,
            $this->postopenRotationCutoff,
            $this->riskExitDayCutoff,
        ] as $clock) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $clock)) {
                throw new \InvalidArgumentException('Execution window clocks must use HH:MM.');
            }
        }
        if ($this->preopenStart >= $this->preopenCutoff
            || $this->preopenCutoff > '09:27'
            || $this->postopenRotationCutoff <= '09:30'
            || $this->postopenRotationCutoff > '09:35'
            || $this->riskExitDayCutoff <= $this->postopenRotationCutoff
            || $this->riskExitDayCutoff > '15:45') {
            throw new \InvalidArgumentException('Execution windows exceed the frozen safety envelope.');
        }
    }

    /** @return array<string,mixed> */
    public function resolve(
        string $sessionDate,
        \DateTimeImmutable $now,
        ?string $signalDate = null,
        bool $brokerMarketOpenConfirmed = false,
    ): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            throw new \InvalidArgumentException('Session date must use YYYY-MM-DD.');
        }
        $timezone = new \DateTimeZone('America/New_York');
        $now = $now->setTimezone($timezone);
        $session = new \DateTimeImmutable($sessionDate, $timezone);
        $queueDate = $signalDate ?? $session->modify('-1 day')->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $queueDate) || $queueDate >= $sessionDate) {
            throw new \InvalidArgumentException('Signal date must precede the intended session.');
        }
        $queueStart = (new \DateTimeImmutable($queueDate, $timezone))->setTime(...$this->clockParts($this->eveningQueueStart));
        $preopenStart = $session->setTime(...$this->clockParts($this->preopenStart));
        $opgExpiresAt = $session->setTime(...$this->clockParts($this->preopenCutoff));
        $marketOpen = $session->setTime(9, 30);
        $rotationExpiresAt = $session->setTime(...$this->clockParts($this->postopenRotationCutoff));
        $currentDate = $now->format('Y-m-d');
        $currentSession = new \DateTimeImmutable($currentDate, $timezone);
        $currentMarketOpen = $currentSession->setTime(9, 30);
        $currentRiskExitExpiresAt = $currentSession->setTime(...$this->clockParts($this->riskExitDayCutoff));
        $lateRiskReduction = $currentDate > $sessionDate;
        $riskExitTemporallyEligible = $currentDate >= $sessionDate
            && $now >= $currentMarketOpen
            && $now < $currentRiskExitExpiresAt;
        $riskExitAllowed = $riskExitTemporallyEligible && $brokerMarketOpenConfirmed;
        $rotationReentryAllowed = $currentDate === $sessionDate
            && $now >= $marketOpen
            && $now < $rotationExpiresAt
            && $brokerMarketOpenConfirmed;

        $opgAllowed = false;
        if ($rotationReentryAllowed && $riskExitAllowed) {
            $status = 'rotation_reentry_and_risk_exit_window';
            $allowed = true;
        } elseif ($riskExitAllowed) {
            $status = $lateRiskReduction ? 'late_risk_exit_recovery_window' : 'risk_exit_recovery_window';
            $allowed = true;
        } elseif ($riskExitTemporallyEligible) {
            $status = 'awaiting_broker_open_confirmation';
            $allowed = false;
        } elseif ($now >= $rotationExpiresAt) {
            $status = 'missed_no_chase';
            $allowed = false;
        } elseif ($now >= $opgExpiresAt) {
            $status = 'locked_for_open';
            $allowed = false;
        } elseif ($now >= $preopenStart) {
            $status = 'submit_preopen';
            $allowed = true;
            $opgAllowed = true;
        } elseif ($now >= $queueStart) {
            $status = 'queue_for_open';
            $allowed = true;
            $opgAllowed = true;
        } else {
            $status = 'waiting_for_opg_window';
            $allowed = false;
        }

        return [
            'scheduled_session' => $sessionDate,
            'resolved_at' => $now->format(\DateTimeInterface::ATOM),
            'status' => $status,
            'submit_allowed' => $allowed,
            'opg_submit_allowed' => $opgAllowed,
            'rotation_reentry_allowed' => $rotationReentryAllowed,
            'risk_exit_day_allowed' => $riskExitAllowed,
            'late_risk_reduction' => $lateRiskReduction,
            'broker_market_open_confirmed' => $brokerMarketOpenConfirmed,
            'no_chase' => true,
            'queue_opens_at' => $queueStart->format(\DateTimeInterface::ATOM),
            'opg_expires_at' => $opgExpiresAt->format(\DateTimeInterface::ATOM),
            'rotation_reentry_expires_at' => $rotationExpiresAt->format(\DateTimeInterface::ATOM),
            'risk_exit_expires_at' => $currentRiskExitExpiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{0:int,1:int} */
    private function clockParts(string $clock): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return [$hour, $minute];
    }
}
