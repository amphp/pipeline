<?php

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterableIterator;
use Amp\Pipeline\ConcurrentIterator;

/**
 * @template T
 * @template R
 *
 * @internal
 */
final class FlatMapOperation implements IntermediateOperation
{
    public static function getStopMarker(): object
    {
        static $marker;

        return $marker ??= new \stdClass;
    }

    private int $concurrency;

    private bool $ordered;

    private bool $lazy;

    /** @var \Closure(T, int):iterable<R> */
    private \Closure $flatMap;

    /**
     * @param int $concurrency
     * @param bool $ordered
     * @param bool $lazy
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(int $concurrency, bool $ordered, bool $lazy, \Closure $flatMap)
    {
        $this->concurrency = $concurrency;
        $this->ordered = $ordered;
        $this->lazy = $lazy;
        $this->flatMap = $flatMap;
    }

    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        if ($this->concurrency === 1) {
            $stop = self::getStopMarker();

            return new ConcurrentIterableIterator((function () use ($source, $stop): iterable {
                foreach ($source as $position => $value) {
                    $iterable = ($this->flatMap)($value, $position);
                    foreach ($iterable as $item) {
                        if ($item === $stop) {
                            return;
                        }

                        yield $item;
                    }
                }
            })(), $this->lazy);
        }

        return new ConcurrentFlatMapIterator($source, $this->concurrency, $this->ordered, $this->lazy, $this->flatMap);
    }
}
