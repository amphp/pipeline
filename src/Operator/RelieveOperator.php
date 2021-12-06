<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\Emitter;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Revolt\EventLoop;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 */
final class RelieveOperator implements Operator
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

        return $emitter->asPipeline();
    }
}
