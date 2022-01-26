<?php

namespace Amp\Pipeline\Internal;

use Amp\Pipeline\ConcurrentIterableIterator;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;
use function Amp\Future\await;

/** @internal */
final class FlatMapOperation implements IntermediateOperation
{
    public static function getStopMarker(): object
    {
        static $marker;

        return $marker ??= new \stdClass;
    }

    private int $concurrency;

    private bool $ordered;

    private \Closure $flatMap;

    public function __construct(int $concurrency, bool $ordered, \Closure $flatMap)
    {
        $this->concurrency = $concurrency;
        $this->ordered = $ordered;
        $this->flatMap = $flatMap;
    }

    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        $destination = new QueueState;
        $stop = self::getStopMarker();

        if ($this->concurrency === 1) {
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

        $futures = [];

        $sequence = $this->ordered ? new Sequence : null;

        for ($i = 0; $i < $this->concurrency; $i++) {
            $futures[] = async(function () use (
                $source,
                $destination,
                $sequence,
                $stop
            ): void {
                foreach ($source as $position => $value) {
                    if ($destination->isComplete()) {
                        return;
                    }

                    // The operation runs concurrently, but the emits are at the correct position
                    $iterable = ($this->flatMap)($value, $position);

                    $sequence?->await($position);

                    foreach ($iterable as $emit) {
                        /** @psalm-suppress TypeDoesNotContainType */
                        if ($emit === $stop || $destination->isComplete()) {
                            return;
                        }

                        $destination->push($emit);
                    }

                    $sequence?->resume($position);
                }
            });
        }

        async(static function () use ($futures, $source, $destination): void {
            try {
                await($futures);
                $destination->complete();
            } catch (\Throwable $exception) {
                $destination->error($exception);
                $source->dispose();
            }
        });

        return new ConcurrentQueueIterator($destination);
    }
}
