#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Pipeline;
use function Amp\async;
use function Amp\delay;
use function Amp\Pipeline\fromIterable;

try {
    /** @psalm-var Pipeline<int> $pipeline */
    $pipeline = fromIterable(function (): \Generator {
        yield 1;
        delay(0.5);
        yield 2;
        yield 3;
        delay(0.3);
        yield 4;
        yield 5;
        yield 6;
        delay(1);
        yield 7;
        yield 8;
        yield 9;
        delay(0.6);
        yield 10;
    });

    // Pipeline consumer attempts to consume 11 values at once. Only 10 will be emitted.
    $futures = [];
    for ($i = 0; $i < 11; ++$i) {
        $futures[] = async(function () use ($pipeline): ?int {
            if ($pipeline->continue()) {
                return $pipeline->get();
            }

            return null;
        });
    }

    foreach ($futures as $key => $future) {
        if (null === $yielded = $future->await()) {
            \printf("Async generator completed after yielding %d values\n", $key);
            break;
        }

        \printf("Async generator yielded %d\n", $yielded);
    }
} catch (\Exception $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
