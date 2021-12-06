<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Revolt\EventLoop;

/**
 * @template TValue
 * @template-implements Pipeline<TValue>
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class AsyncGenerator implements Pipeline, \IteratorAggregate
{
    /** @var Internal\EmitSource<TValue> */
    private Internal\EmitSource $source;

    /**
     * @param \Closure():\Generator $closure
     *
     * @throws \Error Thrown if the closure throws any exception.
     * @throws \TypeError Thrown if the closure does not return a Generator.
     */
    public function __construct(\Closure $closure)
    {
        $this->source = $source = new Internal\EmitSource;

        try {
            $generator = $closure();

            if (!$generator instanceof \Generator) {
                throw new \TypeError(
                    "Return value must be of type Generator, " . \get_debug_type($generator) . " returned"
                );
            }
        } catch (\Throwable $exception) {
            $this->source->error($exception);
            return;
        }

        EventLoop::queue(static function () use ($generator, $source): void {
            try {
                $yielded = $generator->current();

                while ($generator->valid()) {
                    $source->yield($yielded);
                    $yielded = $generator->send(null);
                }

                $source->complete();
            } catch (\Throwable $exception) {
                $source->error($exception);
            }
        });
    }

    public function __destruct()
    {
        $this->source->destroy();
    }

    /**
     * @inheritDoc
     *
     * @psalm-return TValue|null
     */
    public function continue(?Cancellation $cancellation = null): mixed
    {
        return $this->source->continue();
    }

    /**
     * Notifies the generator that the consumer is no longer interested in the generator output.
     *
     * @return void
     */
    public function dispose(): void
    {
        $this->source->dispose();
    }

    public function pipe(Operator ...$operators): Pipeline
    {
        $pipeline = $this;
        foreach ($operators as $operator) {
            $pipeline = $operator->pipe($pipeline);
        }
        return $pipeline;
    }

    public function isComplete(): bool
    {
        return $this->source->isConsumed();
    }

    public function isDisposed(): bool
    {
        return $this->source->isDisposed();
    }

    public function getIterator(): \Traversable
    {
        while (null !== $value = $this->source->continue()) {
            yield $value;
        }
    }
}
