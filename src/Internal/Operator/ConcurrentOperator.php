<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Future;
use Amp\Pipeline\Emitter;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class ConcurrentOperator implements PipelineOperator
{
    /**
     * @param int $concurrency Concurrency limited to consuming the given number of items.
     * @param PipelineOperator[] $operators Set of operators to apply to each concurrent pipeline.
     */
    public function __construct(
        private int $concurrency,
        private array $operators,
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        $destination = new Emitter();

        $futures = [];
        for ($i = 0; $i < $this->concurrency; ++$i) {
            $futures[] = $this->createEmitter($pipeline, $destination);
        }

        EventLoop::queue(static function () use ($futures, $pipeline, $destination): void {
            try {
                Future\await($futures);
                $destination->complete();
            } catch (\Throwable $exception) {
                $destination->error($exception);
                $pipeline->dispose();
            }
        });

        return $destination->pipe();
    }

    private function createEmitter(
        Pipeline $source,
        Emitter $destination,
    ): Future {
        return async(function () use ($source, $destination): void {
            foreach ($this->operators as $operator) {
                $source = $operator->pipe($source);
            }

            while (null !== $value = $source->continue()) {
                if ($destination->isComplete()) {
                    return;
                }

                $destination->yield($value);
            }
        });
    }
}
