<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Emission;

/**
 * @template T
 * @template R
 *
 * @internal
 */
final class FlatMapOperation implements IntermediateOperation
{
    /**
     * @param \Closure(T, int):Emission<R> $flatMap
     */
    public function __construct(
        private readonly int $bufferSize,
        private readonly int $concurrency,
        private readonly bool $ordered,
        private readonly \Closure $flatMap,
    ) {
    }

    #[\Override]
    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        if ($this->concurrency === 1) {
            return new ConcurrentIterableIterator($this->consume($source), $this->bufferSize);
        }

        return new ConcurrentFlatMapIterator(
            $source,
            $this->bufferSize,
            $this->concurrency,
            $this->ordered,
            $this->flatMap,
        );
    }

    private function consume(ConcurrentIterator $source): \Generator
    {
        foreach ($source as $position => $value) {
            $emission = ($this->flatMap)($value, $position);

            yield from $emission;

            if ($emission->isFinal()) {
                return;
            }
        }
    }
}
