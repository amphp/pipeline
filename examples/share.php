#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Future;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

try {
    /** @psalm-var Emitter<int> $source */
    $emitter = new Emitter;

    EventLoop::queue(function () use ($emitter): void {
        // Source emits all values at once without awaiting back-pressure.
        $emitter->emit(1);
        $emitter->emit(2);
        $emitter->emit(3);
        $emitter->emit(4);
        $emitter->emit(5);
        $emitter->emit(6);
        $emitter->emit(7);
        $emitter->emit(8);
        $emitter->emit(9);
        $emitter->emit(10);
        $emitter->complete();
    });

    $source = Pipeline\share($emitter->asPipeline());

    $pipeline1 = $source->asPipeline();
    $pipeline2 = $source->asPipeline();

    $future1 = async(function () use ($pipeline1) {
        foreach ($pipeline1 as $value) {
            \printf("Pipeline source yielded %d\n", $value);
            delay(0.5); // Listener consumption takes 500 ms.
        }
    });

    $future2 = async(function () use ($pipeline2) {
        foreach ($pipeline2 as $value) {
            \printf("Pipeline source yielded %d\n", $value);
            delay(0.1); // Listener consumption takes only 100 ms, but is limited by 500 ms loop above.
        }
    });

    Future\all([$future1, $future2]);
} catch (\Throwable $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
