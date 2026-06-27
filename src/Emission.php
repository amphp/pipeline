<?php declare(strict_types=1);

namespace Amp\Pipeline;

/**
 * @template-covariant T
 * @implements \IteratorAggregate<int, T>
 */
final class Emission implements \IteratorAggregate
{
    /**
     * @template Tv
     * @param iterable<Tv> $values
     * @return self<Tv>
     */
    public static function of(iterable $values): self
    {
        return new self($values, false);
    }

    /**
     * @template Tv
     * @param iterable<Tv> $values
     * @return self<Tv>
     */
    public static function end(iterable $values = []): self
    {
        return new self($values, true);
    }

    /**
     * @param iterable<T> $values
     */
    private function __construct(
        private readonly iterable $values,
        private readonly bool $final,
    ) {
    }

    public function isFinal(): bool
    {
        return $this->final;
    }

    /**
     * @return \Traversable<int, T>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        // Not using yield from to ensure keys are 0-indexed.
        foreach ($this->values as $value) {
            yield $value;
        }
    }
}
