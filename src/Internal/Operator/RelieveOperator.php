<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\Emitter;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use Revolt\EventLoop;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class RelieveOperator implements PipelineOperator
{
    public function pipe(Pipeline $pipeline): Pipeline
    {
        $emitter = new Emitter;

        EventLoop::queue(static function () use ($pipeline, $emitter): void {
            $catch = static function (\Throwable $exception) use ($emitter): void {
                if (!$emitter->isComplete()) {
                    $emitter->error($exception);
                }
            };

            try {
                foreach ($pipeline as $value) {
                    if ($emitter->isComplete()) {
                        return;
                    }

                    $emitter->emit($value)->catch($catch)->ignore();
                }

                if (!$emitter->isComplete()) {
                    $emitter->complete();
                }
            } catch (\Throwable $exception) {
                if (!$emitter->isComplete()) {
                    $emitter->error($exception);
                }
            }
        });

        return $emitter->pipe();
    }
}
