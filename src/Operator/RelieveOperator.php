<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\Emitter;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Revolt\EventLoop;

final class RelieveOperator implements Operator
{
    public function pipe(Pipeline $pipeline): Pipeline
    {
        $subject = new Emitter;

        EventLoop::queue(static function () use ($pipeline, $subject): void {
            $catch = static function (\Throwable $exception) use ($subject): void {
                if (!$subject->isComplete()) {
                    $subject->error($exception);
                }
            };

            try {
                foreach ($pipeline as $value) {
                    if ($subject->isComplete()) {
                        return;
                    }

                    $subject->emit($value)->catch($catch)->ignore();
                }

                if (!$subject->isComplete()) {
                    $subject->complete();
                }
            } catch (\Throwable $exception) {
                if (!$subject->isComplete()) {
                    $subject->error($exception);
                }
            }
        });

        return $subject->asPipeline();
    }
}
