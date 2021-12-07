<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\Emitter;
use Amp\Pipeline\PipelineOperator;
use Amp\Pipeline\Pipeline;
use function Amp\async;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 *
 * @internal
 */
final class FinalizeOperator implements PipelineOperator
{
    /**
     * @param \Closure():void $finalize
     */
    public function __construct(private \Closure $finalize)
    {
    }

    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TValue>
     */
    public function pipe(Pipeline $pipeline): Pipeline
    {
        $emitter = new Emitter();
        $finalize = $this->finalize;

        async(static function () use ($emitter, $pipeline, $finalize): void {
            $exception = null;

            try {
                foreach ($pipeline as $value) {
                    $emitter->yield($value);
                }
            } catch (\Throwable $exception) {
                // $exception used in finally closure.
            } finally {
                async(static function () use ($emitter, $finalize, $exception): void {
                    try {
                        try {
                            if ($exception) {
                                // Rethrow so $exception is attached as previous if $finalize throws.
                                throw $exception;
                            }
                        } finally {
                            $finalize();
                        }

                        $emitter->complete();
                    } catch (\Throwable $exception) {
                        $emitter->error($exception);
                    }
                });
            }
        });

        return $emitter->pipe();
    }
}
