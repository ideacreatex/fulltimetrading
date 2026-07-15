<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class TelegramSignalAlignment
{
    /**
     * @param array<string, mixed> $event
     * @param list<array<string, mixed>> $temporalMatches
     * @return array<string, mixed>
     */
    public function evaluate(
        array $event,
        array $temporalMatches,
        bool $directionAware = true,
        bool $actionAware = true,
        bool $setupAware = false,
    ): array {
        $eventAction = strtolower((string) ($event['message_action'] ?? $event['action'] ?? 'other'));
        $eventDirection = strtolower((string) ($event['market_direction'] ?? 'neutral'));
        $verifiedRealAction = ($event['verified_real_action'] ?? null) === true;
        $requiredDirection = match ($eventDirection) {
            'bullish' => 'long',
            'bearish' => 'short',
            default => null,
        };
        $actionComparable = !$actionAware || (
            in_array($eventAction, ['entry', 'analysis'], true)
            && ($eventAction !== 'entry' || $verifiedRealAction)
        );
        $directionComparable = !$directionAware || $requiredDirection !== null;
        $supportContext = $this->supportContext($event);
        $supportMentions = $supportContext['mentions'];
        $setupAmbiguous = $setupAware && $supportContext['ambiguous'];
        $setupComparable = !$setupAware || ($supportMentions !== [] && !$setupAmbiguous);

        $directionCompatible = [];
        $compatible = [];
        if ($actionComparable && $directionComparable) {
            foreach ($temporalMatches as $match) {
                $signalDirection = strtolower((string) ($match['direction'] ?? 'long'));
                $directionMatches = !$directionAware || $signalDirection === $requiredDirection;
                if (!$directionMatches) {
                    continue;
                }
                $match['event_action'] = $eventAction;
                $match['event_market_direction'] = $eventDirection;
                $match['direction_compatible'] = true;
                $match['action_compatible'] = true;
                $directionCompatible[] = $match;
                $match['setup_compatible'] = !$setupAware
                    || ($setupComparable && $this->matchesSupport($match, $supportMentions));
                if (!$match['setup_compatible']) {
                    continue;
                }
                $compatible[] = $match;
            }
        }

        $reason = match (true) {
            $temporalMatches === [] => 'no_temporal_or_family_match',
            $eventAction === 'entry' && !$verifiedRealAction => 'entry_action_not_verified',
            !$actionComparable => 'event_action_not_entry_signal_comparable',
            !$directionComparable => 'event_direction_not_explicit',
            $directionCompatible === [] => 'opposite_signal_direction',
            $setupAmbiguous => 'event_support_mentions_not_ticker_bound',
            !$setupComparable => 'event_setup_not_explicit',
            $compatible === [] => 'support_timeframe_or_ma_mismatch',
            default => 'aligned',
        };

        return [
            'matched' => $compatible !== [],
            'reason' => $reason,
            'event_action' => $eventAction,
            'verified_real_action' => $verifiedRealAction,
            'event_market_direction' => $eventDirection,
            'required_signal_direction' => $requiredDirection,
            'action_comparable' => $actionComparable,
            'direction_comparable' => $directionComparable,
            'setup_comparable' => $setupComparable,
            'setup_ambiguous' => $setupAmbiguous,
            'coarse_matched' => $directionCompatible !== [],
            'direction_mismatch' => $temporalMatches !== []
                && $actionComparable
                && $directionComparable
                && $directionCompatible === [],
            'setup_mismatch' => $setupAware
                && $setupComparable
                && $directionCompatible !== []
                && $compatible === [],
            'temporal_matches' => $temporalMatches,
            'coarse_matches' => $directionCompatible,
            'matches' => $compatible,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{mentions:list<array{period:int,type:string,timeframe:string}>,ambiguous:bool}
     */
    private function supportContext(array $event): array
    {
        $comparisonTicker = strtoupper(trim((string) ($event['comparison_ticker'] ?? '')));
        $tickerBound = is_array($event['ticker_support_mentions'] ?? null)
            ? $event['ticker_support_mentions']
            : [];
        $tickerMentions = null;
        foreach ($tickerBound as $ticker => $mentions) {
            if (strtoupper(trim((string) $ticker)) === $comparisonTicker) {
                $tickerMentions = $mentions;
                break;
            }
        }
        if ($comparisonTicker !== '' && $tickerMentions !== null) {
            return [
                'mentions' => $this->supportMentions($tickerMentions),
                'ambiguous' => false,
            ];
        }

        $mentions = $this->supportMentions($event['support_mentions'] ?? []);
        $tickers = array_values(array_unique(array_filter(array_map(
            static fn (mixed $ticker): string => strtoupper(trim((string) $ticker)),
            is_array($event['tickers'] ?? null) ? $event['tickers'] : [],
        ))));

        return [
            'mentions' => $mentions,
            'ambiguous' => $mentions !== [] && count($tickers) > 1,
        ];
    }

    /** @param mixed $mentions @return list<array{period:int,type:string,timeframe:string}> */
    private function supportMentions(mixed $mentions): array
    {
        if (!is_array($mentions)) {
            return [];
        }

        $normalized = [];
        foreach ($mentions as $mention) {
            if (!is_array($mention)) {
                continue;
            }
            $period = (int) ($mention['period'] ?? 0);
            $type = strtoupper((string) ($mention['type'] ?? $mention['kind'] ?? ''));
            $timeframe = strtoupper((string) ($mention['timeframe'] ?? ''));
            if ($period <= 0 || $type === '' || $timeframe === '') {
                continue;
            }
            $normalized[] = [
                'period' => $period,
                'type' => $type,
                'timeframe' => $timeframe,
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $match @param list<array{period:int,type:string,timeframe:string}> $mentions */
    private function matchesSupport(array $match, array $mentions): bool
    {
        $metadata = is_array($match['metadata'] ?? null) ? $match['metadata'] : [];
        $period = (int) ($metadata['ma_period'] ?? 0);
        $type = strtoupper((string) ($metadata['ma_type'] ?? ''));
        $timeframe = strtoupper((string) ($metadata['timeframe'] ?? ''));
        if ($period <= 0 || $type === '' || $timeframe === '') {
            return false;
        }

        foreach ($mentions as $mention) {
            $typeMatches = $mention['type'] === 'MA' || $mention['type'] === $type;
            if ($typeMatches && $mention['period'] === $period && $mention['timeframe'] === $timeframe) {
                return true;
            }
        }

        return false;
    }
}
