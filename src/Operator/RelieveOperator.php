<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Subject;
use function Revolt\EventLoop\defer;

/**
 * @template TValue
 *
 * @template-implements Operator<TValue, TValue>
 */
final class RelieveOperator implements Operator
{
    /**
     * @param Pipeline<TValue> $pipeline
     * @return Pipeline<TValue>
     */
    public function pipe(Pipeline $pipeline): Pipeline
    {
        $subject = new Subject();

        defer(function () use ($pipeline, $subject): void {
            try {
                foreach ($pipeline as $value) {
                    $subject->emit($value);
                }
                $subject->complete();
            } catch (\Throwable $exception) {
                $subject->error($exception);
            }
        });

        return $subject->asPipeline();
    }
}
