#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Subject;
use function Revolt\EventLoop\defer;
use function Revolt\EventLoop\delay;

try {
    /** @psalm-var Subject<int> $source */
    $source = new Subject;
    $pipeline = $source->asPipeline();

    defer(function () use ($source): void {
        delay(0.5);
        $source->yield(1);
        delay(1.5);
        $source->yield(2);
        delay(1);
        $source->yield(3);
        delay(2);
        $source->yield(4);
        $source->yield(5);
        $source->yield(6);
        $source->yield(7);
        delay(2);
        $source->yield(8);
        $source->yield(9);
        $source->yield(10);
        $source->complete();
    });

    foreach ($pipeline as $value) {
        \printf("Pipeline source yielded %d\n", $value);
        delay(0.5); // Listener consumption takes 500 ms.
    }
} catch (\Exception $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
