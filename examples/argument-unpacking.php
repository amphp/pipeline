#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Pipeline;
use Revolt\EventLoop;
use function Amp\delay;
use function Amp\Pipeline\fromIterable;

try {
    // Unreference timer so the loop exits automatically when all tasks complete.
    EventLoop::unreference(EventLoop::repeat(0.1, function () {
        echo "."; // This repeat timer is to show the loop is not being blocked.
    }));

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

    echo "Unpacking arguments, please wait...\n";
    \var_dump(...$pipeline);
} catch (\Exception $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
