#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Emitter;
use Revolt\EventLoop;
use function Amp\delay;

try {
    /** @psalm-var Emitter<int> $source */
    $emitter = new Emitter;
    $pipeline = $emitter->pipe();

    EventLoop::queue(function () use ($emitter): void {
        delay(0.5);
        $emitter->yield(1);
        delay(1.5);
        $emitter->yield(2);
        delay(1);
        $emitter->yield(3);
        delay(2);
        $emitter->yield(4);
        $emitter->yield(5);
        $emitter->yield(6);
        $emitter->yield(7);
        delay(2);
        $emitter->yield(8);
        $emitter->yield(9);
        $emitter->yield(10);
        $emitter->complete();
    });

    foreach ($pipeline as $value) {
        \printf("Pipeline source yielded %d\n", $value);
        delay(0.5); // Listener consumption takes 500 ms.
    }
} catch (\Exception $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
