<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BlockBootstrapAnalyzer;

require __DIR__ . '/../bootstrap.php';

function bootstrapAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$curve = [];
$equity = 100.0;
for ($i = 0; $i < 300; $i++) {
    $equity *= 1.001;
    $curve[] = [
        'date' => (new DateTimeImmutable('2025-01-01'))->modify('+' . $i . ' days')->format('Y-m-d'),
        'equity' => $equity,
    ];
}

$analyzer = new BlockBootstrapAnalyzer();
$first = $analyzer->analyze($curve, 100, 20, 42);
$second = $analyzer->analyze(array_reverse($curve), 100, 20, 42);
bootstrapAssert($first === $second, 'Bootstrap output must be deterministic and independent of input ordering.');
bootstrapAssert((float) $first['cagr_q05'] > 0.0, 'An all-positive return path must have a positive lower-tail CAGR.');
bootstrapAssert(abs((float) $first['max_drawdown_q95']) < 1.0e-12, 'An all-positive return path must have no drawdown.');
bootstrapAssert((float) $first['positive_cagr_probability'] === 1.0, 'All positive bootstrap paths must remain positive.');

echo "Block bootstrap analyzer OK\n";
