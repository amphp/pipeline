#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\AsyncGenerator;
use Revolt\EventLoop;
use function Amp\coroutine;
use function Amp\delay;

$future = coroutine(function (): void {
    try {
        $timer = EventLoop::repeat(0.1, function () {
            echo ".", PHP_EOL; // This repeat timer is to show the loop is not being blocked.
        });
        EventLoop::unreference($timer); // Unreference timer so the loop exits automatically when all tasks complete.

        /** @psalm-var AsyncGenerator<int, null, null> $pipeline */
        $pipeline = new AsyncGenerator(function (): \Generator {
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

        echo "Unpacking AsyncGenerator, please wait...\n";
        \var_dump(...$pipeline);
    } catch (\Exception $exception) {
        \printf("Exception: %s\n", (string) $exception);
    }
});

$future->await();
