<?php

namespace Amp\Pipeline\Operator;

use Amp\Deferred;
use Amp\Future;
use Amp\Pipeline\AsyncGenerator;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use function Revolt\EventLoop\defer;

final class SampleWhenOperator implements Operator
{
    private mixed $current;
    private bool $sampled = true;
    private Deferred $deferred;

    public function __construct(
        private Pipeline $sampleWhen
    ) {
        $this->deferred = new Deferred();
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        defer(function () use ($pipeline): void {
            try {
                foreach ($pipeline as $this->current) {
                    $this->sampled = false;
                }
                $this->deferred->complete(null);
            } catch (\Throwable $exception) {
                $this->deferred->error($exception);
            }
        });

        return new AsyncGenerator(function (): \Generator {
            while (
                Future\first([
                    $this->deferred->getFuture(),
                    Future\spawn(fn() => $this->sampleWhen->continue())
                ]) !== null
            ) {
                if ($this->sampled) {
                    continue;
                }

                $this->sampled = true;
                yield $this->current;
            }
        });
    }
}
