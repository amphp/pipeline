<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\PipelineOperator;
use Revolt\EventLoop;

/**
 * @template TValue
 * @template-implements Pipeline<TValue>
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class GeneratorPipeline implements Pipeline, \IteratorAggregate
{
    /** @var EmitSource<TValue> */
    private EmitSource $source;

    public function __construct(\Generator $generator)
    {
        $this->source = $source = new EmitSource;

        EventLoop::queue(static function () use ($generator, $source): void {
            try {
                foreach ($generator as $value) {
                    $source->yield($value);
                }

                $source->complete();
            } catch (\Throwable $exception) {
                $source->error($exception);
            }
        });
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

    /**
     * @template TResult
     *
     * @param PipelineOperator ...$operators
     *
     * @return Pipeline<TResult>
     */
    public function pipe(PipelineOperator ...$operators): Pipeline
    {
        $pipeline = $this;
        foreach ($operators as $operator) {
            $pipeline = $operator->pipe($pipeline);
        }

        /** @var Pipeline<TResult> $pipeline */
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
