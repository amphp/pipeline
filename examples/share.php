#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Future;
use Amp\Pipeline;
use Amp\Pipeline\Subject;
use function Amp\coroutine;
use function Amp\delay;
use function Revolt\EventLoop\queue;

try {
    /** @psalm-var Subject<int> $source */
    $source = new Subject;

    queue(function () use ($source): void {
        // Source emits all values at once without awaiting back-pressure.
        $source->emit(1);
        $source->emit(2);
        $source->emit(3);
        $source->emit(4);
        $source->emit(5);
        $source->emit(6);
        $source->emit(7);
        $source->emit(8);
        $source->emit(9);
        $source->emit(10);
        $source->complete();
    });

    $source = Pipeline\share($source->asPipeline());

    $pipeline1 = $source->asPipeline();
    $pipeline2 = $source->asPipeline();

    $future1 = coroutine(function () use ($pipeline1) {
        // Use Amp\Pipeline\toIterator() to use a pipeline with foreach.
        foreach ($pipeline1 as $value) {
            \printf("Pipeline source yielded %d\n", $value);
            delay(0.5); // Listener consumption takes 500 ms.
        }
    });

    $future2 = coroutine(function () use ($pipeline2) {
        foreach ($pipeline2 as $value) {
            \printf("Pipeline source yielded %d\n", $value);
            delay(0.1); // Listener consumption takes only 100 ms, but is limited by 500 ms loop above.
        }
    });

    Future\all([$future1, $future2]);
} catch (\Throwable $exception) {
    \printf("Exception: %s\n", (string) $exception);
}
