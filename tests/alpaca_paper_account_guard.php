<?php

declare(strict_types=1);

use FulltimeTrading\Trading\AlpacaPaperAccountGuard;

require __DIR__ . '/../bootstrap.php';

function accountGuardExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function accountGuardExpectFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        accountGuardExpect(!str_contains($e->getMessage(), 'expected-account-secret'), 'Guard error leaked the expected account reference.');
        accountGuardExpect(!str_contains($e->getMessage(), 'wrong-account-secret'), 'Guard error leaked the actual account reference.');

        return;
    }

    throw new RuntimeException($message);
}

$environmentNames = [
    'APCA_PAPER_ACCOUNT_ID',
    'APCA_PAPER_EXPECTED_MULTIPLIER',
    'APCA_PAPER_EXPECTED_SHORTING_ENABLED',
];
$previousEnvironment = [];
foreach ($environmentNames as $name) {
    $previousEnvironment[$name] = getenv($name);
}

try {
    putenv('APCA_PAPER_ACCOUNT_ID=expected-account-secret');
    putenv('APCA_PAPER_EXPECTED_MULTIPLIER=2');
    putenv('APCA_PAPER_EXPECTED_SHORTING_ENABLED=true');

    $account = [
        'id' => 'expected-account-secret',
        'account_number' => 'PA1234',
        'multiplier' => '2',
        'shorting_enabled' => true,
        'status' => 'ACTIVE',
        'trading_blocked' => false,
        'account_blocked' => false,
    ];
    $result = AlpacaPaperAccountGuard::validateConfigured($account);
    accountGuardExpect($result === [
        'account_reference_match' => true,
        'multiplier_match' => true,
        'shorting_match' => true,
        'active' => true,
        'unblocked' => true,
    ], 'A valid configured paper account did not pass every guard.');

    $accountNumberMatch = $account;
    $accountNumberMatch['id'] = 'different-id';
    $accountNumberMatch['account_number'] = 'expected-account-secret';
    AlpacaPaperAccountGuard::validateConfigured($accountNumberMatch);

    $wrongIdentity = $account;
    $wrongIdentity['id'] = 'wrong-account-secret';
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($wrongIdentity),
        'A wrong paper account identity was accepted.',
    );

    $wrongMultiplier = $account;
    $wrongMultiplier['multiplier'] = '4';
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($wrongMultiplier),
        'A wrong paper multiplier was accepted.',
    );

    $wrongShorting = $account;
    $wrongShorting['shorting_enabled'] = false;
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($wrongShorting),
        'A wrong paper shorting flag was accepted.',
    );

    foreach (['shorting_enabled', 'trading_blocked', 'account_blocked'] as $missingField) {
        $incomplete = $account;
        unset($incomplete[$missingField]);
        accountGuardExpectFailure(
            static fn () => AlpacaPaperAccountGuard::validateConfigured($incomplete),
            'A paper account with missing safety field was accepted: ' . $missingField,
        );
    }

    $blocked = $account;
    $blocked['trading_blocked'] = true;
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($blocked),
        'A blocked paper account was accepted.',
    );

    $inactive = $account;
    $inactive['status'] = 'ACCOUNT_UPDATED';
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($inactive),
        'A non-active paper account was accepted.',
    );

    foreach ($environmentNames as $missingName) {
        $saved = getenv($missingName);
        putenv($missingName);
        accountGuardExpectFailure(
            static fn () => AlpacaPaperAccountGuard::validateConfigured($account),
            'A missing mandatory guard setting was accepted: ' . $missingName,
        );
        if (is_string($saved)) {
            putenv($missingName . '=' . $saved);
        }
    }

    putenv('APCA_PAPER_EXPECTED_SHORTING_ENABLED=not-a-boolean');
    accountGuardExpectFailure(
        static fn () => AlpacaPaperAccountGuard::validateConfigured($account),
        'An invalid expected shorting value was accepted.',
    );
} finally {
    foreach ($previousEnvironment as $name => $value) {
        if (is_string($value)) {
            putenv($name . '=' . $value);
        } else {
            putenv($name);
        }
    }
}

echo "Alpaca paper account guard OK\n";
