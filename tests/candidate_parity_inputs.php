<?php
declare(strict_types=1);
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\CandidateParityInputs as Inputs;
require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$bars = [];
foreach (['AAA' => '2017-01-03', 'PLTR' => '2020-09-30'] as $symbol => $date) {
    $bars[$symbol] = [new Bar($symbol, new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('America/New_York')), 10., 11., 9., 10., 100.)];
}
$config = ['universe' => ['AAA', 'PLTR'], 'asset_sma_period' => 50, 'whole_share_execution' => true];
$prefix = Inputs::prefixConfig($config, $bars, '2019-01-03');
$check($prefix['universe'] === ['AAA'], 'Do not require unavailable pre-IPO bars.');
$check($prefix['asset_sma_period'] === 50 && $prefix['whole_share_execution'] === true, 'Do not change strategy or sizing.');
$check(Inputs::prefixConfig($config, $bars, '2020-09-30') === $config, 'Keep symbol from first genuine observation onward.');
$check($config['universe'] === ['AAA', 'PLTR'], 'Original replay universe remains unchanged.');
foreach ([['universe' => ['MISSING']], ['universe' => ['PLTR']]] as $bad) {
    try { Inputs::prefixConfig($bad, $bars, '2019-01-03'); } catch (RuntimeException) { $check(true, 'Missing source or entirely unavailable prefix rejected.'); continue; }
    $check(false, 'Invalid prefix accepted.');
}
$c = ['date' => '2019-01-03', 'desired' => ['AAA' => .5], 'closes' => ['AAA' => 10.], 'previous_closes' => ['AAA' => 9.], 'volatility' => ['AAA' => .2, 'PLTR' => null]];
$expected = $c; unset($expected['volatility']['PLTR']);
$check(Inputs::comparableContext($c, ['PLTR']) === $expected, 'Only absent pre-IPO null metadata normalized.');
$check(Inputs::comparableContext($expected, ['PLTR']) === $expected, 'Already absent metadata unchanged.');
$check(Inputs::comparableContext($c, []) === $c, 'No broad null filtering.');
foreach (['desired', 'closes', 'previous_closes', 'volatility'] as $field) {
    $bad = $c; $bad[$field]['PLTR'] = .1;
    try { Inputs::comparableContext($bad, ['PLTR']); } catch (RuntimeException) { $check(true, 'Future observation rejected.'); continue; }
    $check(false, 'Leaked observation accepted.');
}
echo "Candidate parity inputs: $n assertions PASS\n";
