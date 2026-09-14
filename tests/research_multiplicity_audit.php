<?php

declare(strict_types=1);

use FulltimeTrading\Research\ResearchMultiplicityAudit;

require dirname(__DIR__) . '/bootstrap.php';
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$zero = array_fill(0, 41, 0.0);
$gain = array_fill(0, 41, 0.001);
$loss = array_fill(0, 41, -0.001);
$assert(ResearchMultiplicityAudit::run(['a' => $zero], 10, 99)['familywise_p_value'] === 1.0, 'Identical paths cannot show significance.');
$assert(ResearchMultiplicityAudit::run(['a' => $loss], 10, 99)['familywise_p_value'] === 1.0, 'Losing family must not reject.');
$one = ResearchMultiplicityAudit::run(['a' => $gain], 10, 99);
$assert($one['familywise_p_value'] === 0.01, 'Constant known advantage must pass a noiseless fixture.');
$two = ResearchMultiplicityAudit::run(['a' => $gain, 'b' => $gain], 10, 99);
$assert($one['familywise_p_value'] === $two['familywise_p_value'], 'Duplicating correlated paths must not create significance.');
$wave = array_map(static fn (int $i): float => sin($i) * 0.01 + 0.0001, range(0, 40));
$assert(ResearchMultiplicityAudit::run(['a' => $wave], 10, 99) === ResearchMultiplicityAudit::run(['a' => $wave], 10, 99), 'Bootstrap must reproduce exactly.');
foreach ([['a' => [0.1]], ['a' => [0.1, NAN]], ['a' => [0.1, 0.2], 'b' => [0.1]]] as $bad) {
    $rejected = false;
    try { ResearchMultiplicityAudit::run($bad); } catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Invalid bootstrap input accepted.');
}
echo "Research multiplicity audit tests OK\n";
