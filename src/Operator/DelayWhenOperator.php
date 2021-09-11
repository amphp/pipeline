<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Subject;
use function Revolt\EventLoop\defer;

final class DelayWhenOperator implements Operator
{
    public function __construct(
        private Pipeline $delay
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
//        $subject = new Subject();
//
//        defer(function () use ($pipeline, $subject): void {
//            foreach ($pipeline as $value) {
//                if ($this->delay->continue() === null) {
//                    return;
//                }
//
//                $subject->emit($value);
//            }
//
//            $subject->complete();
//        });
//
//        return $subject->asPipeline();

        return new AsyncGenerator(function () use ($pipeline): \Generator {
            foreach ($pipeline as $value) {
                if ($this->delay->continue() === null) {
                    return;
                }

                yield $value;
            }
        });
    }
}
