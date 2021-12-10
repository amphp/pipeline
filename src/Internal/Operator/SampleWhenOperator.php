<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Pipeline\fromIterable;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class SampleWhenOperator implements PipelineOperator
{
    /**
     * @param Pipeline<mixed> $sampleWhen
     */
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
                $deferred->complete();
            } catch (\Throwable $exception) {
                $deferred->error($exception);
            }
        });

        return fromIterable(function () use (&$sampled, &$current, $deferred): \Generator {
            while (
                Future\race([
                    $deferred->getFuture(),
                    async(fn () => $this->sampleWhen->continue())
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
