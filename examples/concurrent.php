#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline;
use Amp\Pipeline\AsyncGenerator;
use Amp\Sync\LocalSemaphore;
use function Amp\delay;

$pipeline = new AsyncGenerator(function (): \Generator {
    for ($i = 0; $i < 100; ++$i) {
        yield $i;
    }
});

$results = $pipeline->pipe(
    // Output from Pipeline\concurrentOrdered() will come in "bursts" as slower values prevent emitting prior values.
    Pipeline\concurrentOrdered( // Change to Pipeline\concurrentUnordered() for unordered output.
        new LocalSemaphore(10),
        Pipeline\map(function (int $input): int {
            delay(\random_int(1, 10) / 10); // Delay for 0.1 to 1 seconds, simulating I/O.
            return $input * 10;
        })
    ),
);

foreach ($results as $value) {
    echo $value, "\n";
}
