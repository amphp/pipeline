<?php

namespace Amp\Pipeline\Operator;

use Amp\Deferred;
use Amp\Future;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use function Revolt\EventLoop\defer;

final class SampleWhenOperator implements Operator
{
    public function __construct(
        private Pipeline $sampleWhen
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        $deferred = new Deferred;
        $sampled = true;

        defer(function () use (&$sampled, &$current, $deferred, $pipeline): void {
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
                Future\first([
                    $deferred->getFuture(),
                    Future\spawn(fn() => $this->sampleWhen->continue())
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
