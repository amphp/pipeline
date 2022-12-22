<?php declare(strict_types=1);

namespace Amp\Pipeline\Internal;

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

    private readonly int $concurrency;

    private readonly bool $ordered;

    /** @var \Closure(T, int):iterable<R> */
    private readonly \Closure $flatMap;

    /**
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(int $concurrency, bool $ordered, \Closure $flatMap)
    {
        $this->concurrency = $concurrency;
        $this->ordered = $ordered;
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
            })());
        }

        return new ConcurrentFlatMapIterator($source, $this->concurrency, $this->ordered, $this->flatMap);
    }
}
