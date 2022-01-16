<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Emitter;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use Revolt\EventLoop;
use function Amp\async;

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

        $emitter = new Emitter();
        $sampleWhen = $this->sampleWhen;
        EventLoop::queue(static function () use (&$sampled, &$current, $sampleWhen, $deferred, $emitter): void {
            try {
                while (
                    Future\awaitFirst([
                        $deferred->getFuture(),
                        async(static fn () => $sampleWhen->continue())
                    ]) !== null
                ) {
                    if ($sampled) {
                        continue;
                    }

                    $sampled = true;
                    $emitter->yield($current);
                }
            } catch (\Throwable $exception) {
                $emitter->error($exception);
                return;
            }

            $emitter->complete();
        });

        return $emitter->pipe();
    }
}
