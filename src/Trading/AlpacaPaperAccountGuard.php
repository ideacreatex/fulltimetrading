<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class AlpacaPaperAccountGuard
{
    /**
     * @param array<string, mixed> $account
     * @return array{account_reference_match:true,multiplier_match:true,shorting_match:true,active:true,unblocked:true}
     */
    public static function validateConfigured(array $account): array
    {
        $expectedReference = self::requiredEnvironmentValue('APCA_PAPER_ACCOUNT_ID');
        $expectedMultiplier = self::requiredEnvironmentValue('APCA_PAPER_EXPECTED_MULTIPLIER');
        $expectedShortingRaw = self::requiredEnvironmentValue('APCA_PAPER_EXPECTED_SHORTING_ENABLED');

        if (!self::referenceMatches($expectedReference, $account)) {
            throw new \RuntimeException('Alpaca paper account identity validation failed.');
        }

        if (!is_numeric($expectedMultiplier)) {
            throw new \RuntimeException('APCA_PAPER_EXPECTED_MULTIPLIER must be numeric.');
        }
        $actualMultiplier = $account['multiplier'] ?? null;
        if (!is_scalar($actualMultiplier) || !is_numeric((string) $actualMultiplier)) {
            throw new \RuntimeException('Alpaca paper account multiplier is missing or invalid.');
        }
        if (abs((float) $expectedMultiplier - (float) $actualMultiplier) > 1.0e-9) {
            throw new \RuntimeException('Alpaca paper account multiplier validation failed.');
        }

        $expectedShorting = filter_var($expectedShortingRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($expectedShorting === null) {
            throw new \RuntimeException('APCA_PAPER_EXPECTED_SHORTING_ENABLED must be a boolean value.');
        }
        if (!array_key_exists('shorting_enabled', $account) || !is_bool($account['shorting_enabled'])) {
            throw new \RuntimeException('Alpaca paper account shorting flag is missing or invalid.');
        }
        if ($account['shorting_enabled'] !== $expectedShorting) {
            throw new \RuntimeException('Alpaca paper account shorting validation failed.');
        }

        $status = $account['status'] ?? null;
        if (!is_scalar($status) || strtoupper(trim((string) $status)) !== 'ACTIVE') {
            throw new \RuntimeException('Alpaca paper account is not active.');
        }

        foreach (['trading_blocked', 'account_blocked'] as $field) {
            if (!array_key_exists($field, $account) || !is_bool($account[$field])) {
                throw new \RuntimeException('Alpaca paper account safety flags are missing or invalid.');
            }
            if ($account[$field]) {
                throw new \RuntimeException('Alpaca paper account is blocked.');
            }
        }

        return [
            'account_reference_match' => true,
            'multiplier_match' => true,
            'shorting_match' => true,
            'active' => true,
            'unblocked' => true,
        ];
    }

    private static function requiredEnvironmentValue(string $name): string
    {
        $value = getenv($name);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new \RuntimeException($name . ' is required for guarded Alpaca paper submission.');
        }

        return $value;
    }

    /** @param array<string, mixed> $account */
    private static function referenceMatches(string $expected, array $account): bool
    {
        foreach (['id', 'account_number'] as $field) {
            $actual = $account[$field] ?? null;
            if (!is_scalar($actual)) {
                continue;
            }
            $actual = trim((string) $actual);
            if ($actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        return false;
    }
}
