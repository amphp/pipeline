#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\Pipeline\Queue;
use function Amp\async;
use function Amp\delay;

try {
    /** @psalm-var Queue<int> $source */
    $queue = new Queue;
    $pipeline = $queue->pipe();

    async(function () use ($queue): void {
        delay(0.5);
        $queue->push(1);
        delay(1.5);
        $queue->push(2);
        delay(1);
        $queue->push(3);
        delay(2);
        $queue->push(4);
        $queue->push(5);
        $queue->push(6);
        $queue->push(7);
        delay(2);
        $queue->push(8);
        $queue->push(9);
        $queue->push(10);
        $queue->complete();
    });

    foreach ($pipeline as $value) {
        printf("Pipeline source yielded %d\n", $value);
        delay(0.5); // Listener consumption takes 500 ms.
    }
} catch (\Exception $exception) {
    printf("Exception: %s\n", (string) $exception);
}
