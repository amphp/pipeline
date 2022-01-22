<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;

/** @internal */
final class ConcurrentSourceIterator implements ConcurrentIterator, \IteratorAggregate
{
    private Source $source;

    public function __construct(Source $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        $this->source->dispose();
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        return $this->source->continue($cancellation);
    }

    public function get(): mixed
    {
        return $this->source->get();
    }

    public function dispose(): void
    {
        $this->source->dispose();
    }

    public function getIterator(): \Traversable
    {
        // Don't replace this with: return $this->source->getIterator();
        // PHP will call getIterator and GC this instance, triggering a dispose operation.

        while ($this->continue()) {
            yield $this->get();
        }
    }
}
