<?php

namespace Amp\Pipeline;

use Amp\CancellationToken;
use Revolt\EventLoop;

/**
 * @template TValue
 *
 * @template-implements Pipeline<TValue>
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class AsyncGenerator implements Pipeline, \IteratorAggregate
{
    /** @var Internal\EmitSource<TValue> */
    private Internal\EmitSource $source;

    /**
     * @param callable():\Generator $callable
     *
     * @throws \Error Thrown if the callable throws any exception.
     * @throws \TypeError Thrown if the callable does not return a Generator.
     */
    public function __construct(callable $callable)
    {
        $this->source = $source = new Internal\EmitSource;

        try {
            $generator = $callable();

            if (!$generator instanceof \Generator) {
                throw new \TypeError("The callable did not return a Generator");
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
    public function continue(?CancellationToken $token = null): mixed
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

    /**
     * @inheritDoc
     */
    public function pipe(Operator ...$operators): Pipeline
    {
        $pipeline = $this;
        foreach ($operators as $operator) {
            $pipeline = $operator->pipe($pipeline);
        }
        return $pipeline;
    }

    /**
     * @inheritDoc
     */
    public function isComplete(): bool
    {
        return $this->source->isComplete();
    }

    /**
     * @inheritDoc
     */
    public function isDisposed(): bool
    {
        return $this->source->isDisposed();
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Traversable
    {
        while (null !== $value = $this->source->continue()) {
            yield $value;
        }
    }
}
