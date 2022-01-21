#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline;
use function Amp\delay;

$pipeline = Pipeline\fromIterable(function (): \Generator {
    for ($i = 0; $i < 100; ++$i) {
        yield $i;
    }
});

$results = $pipeline->concurrent(10)
        ->tap(fn () => delay(\random_int(1, 10) / 10))  // Delay for 0.1 to 1 seconds, simulating I/O.
        ->map(fn (int $input): int => $input * 10)
        ->filter(fn (int $input) => $input % 3 === 0); // Filter only values divisible by 3.

foreach ($results as $value) {
    echo $value, "\n";
}
