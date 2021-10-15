<?php

namespace Amp\Pipeline\Operator;

use Amp\Deferred;
use Amp\Future;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use function Amp\coroutine;
use function Revolt\launch;

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

        launch(function () use (&$sampled, &$current, $deferred, $pipeline): void {
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
                    coroutine(fn() => $this->sampleWhen->continue())
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
