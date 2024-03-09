#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Pipeline;
use function Amp\delay;

$pipeline = Pipeline::fromIterable(function (): \Generator {
    for ($i = 0; $i < 100; ++$i) {
        yield $i;
    }
});

$pipeline = $pipeline
    ->concurrent(10) // Process up to 10 items concurrently
    ->unordered() // Results may be consumed eagerly and out of order
    ->tap(fn () => delay(random_int(1, 10) / 10)) // Observe each value with a delay for 0.1 to 1 seconds, simulating I/O
    ->map(fn (int $input) => $input * 10) // Apply an operation to each value
    ->filter(fn (int $input) => $input % 3 === 0); // Filter only values divisible by 3

foreach ($pipeline as $value) {
    echo $value, "\n";
}
