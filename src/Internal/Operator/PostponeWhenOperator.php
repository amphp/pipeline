<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

/**
 * @template TValue
 * @template-implements Operator<TValue, TValue>
 *
 * @internal
 */
final class PostponeWhenOperator implements Operator
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
