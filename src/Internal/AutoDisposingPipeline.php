<?php

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Source;

/**
 * Wraps an EmitSource instance that has public methods to emit, complete, and fail into an object that only allows
 * access to the public API methods and automatically calls EmitSource::destroy() when the object is destroyed.
 *
 * @internal
 *
 * @template TValue
 * @template-implements Pipeline<TValue>
 * @template-implements \IteratorAggregate<int, TValue>
 */
final class AutoDisposingPipeline implements Pipeline, \IteratorAggregate
{
    /** @var EmitSource<TValue, null> */
    private EmitSource $source;

    public function __construct(EmitSource $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        $this->source->destroy();
    }

    /**
     * @inheritDoc
     */
    public function continue(): mixed
    {
        return $this->source->continue();
    }

    /**
     * @inheritDoc
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
        if (empty($operators)) {
            return $this;
        }

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
     *
     * @psalm-return \Traversable<int, TValue>
     */
    public function getIterator(): \Traversable
    {
        while (null !== $value = $this->source->continue()) {
            yield $value;
        }
    }
}
