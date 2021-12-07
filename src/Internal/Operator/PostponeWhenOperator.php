<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\PipelineOperator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class PostponeWhenOperator implements PipelineOperator
{
    public function __construct(
        private Pipeline $postpone
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        return new AsyncGenerator(function () use ($pipeline): \Generator {
            while ($this->postpone->continue() !== null) {
                $value = $pipeline->continue();
                if ($value === null) {
                    $this->postpone->dispose();
                    return;
                }

                yield $value;
            }
        });
    }
}
