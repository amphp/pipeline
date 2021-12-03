<?php

namespace Amp\Pipeline\Operator;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Revolt\EventLoop;
use function Amp\async;

final class SampleWhenOperator implements Operator
{
    public function __construct(
        private Pipeline $sampleWhen
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        $deferred = new DeferredFuture;
        $sampled = true;

        EventLoop::queue(function () use (&$sampled, &$current, $deferred, $pipeline): void {
            try {
                foreach ($pipeline as $current) {
                    $sampled = false;
                }
                $deferred->complete(null);
            } catch (\Throwable $exception) {
                $deferred->error($exception);
            }
        });

        return new AsyncGenerator(function () use (&$sampled, &$current, $deferred): \Generator {
            while (
                Future\race([
                    $deferred->getFuture(),
                    async(fn() => $this->sampleWhen->continue())
                ]) !== null
            ) {
                if ($sampled) {
                    continue;
                }

                $sampled = true;
                yield $current;
            }
        });
    }
}
