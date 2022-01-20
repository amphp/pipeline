<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use function Amp\Pipeline\fromIterable;

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
        return fromIterable(function () use ($pipeline): \Generator {
            while ($this->postpone->continue()) {
                if (!$pipeline->continue()) {
                    $this->postpone->dispose();
                    return;
                }

                yield $pipeline->get();
            }
        });
    }
}
