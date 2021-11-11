<?php

namespace Amp\Pipeline\Operator;

use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;

final class PostponeUntilOperator implements Operator
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
