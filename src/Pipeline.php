<?php

namespace Amp\Pipeline;

use Amp\Future;
use Amp\Pipeline\Internal\ConcurrentSourceIterator;
use Amp\Pipeline\Internal\Sequence;
use Amp\Pipeline\Internal\Source;
use function Amp\async;

/**
 * A pipeline is an asynchronous set of ordered values.
 *
 * @template T
 * @template-implements \IteratorAggregate<int, T>
 */
final class Pipeline implements \IteratorAggregate
{
    private ConcurrentIterator $source;

    private int $concurrency = 1;

    private bool $ordered = true;

    private array $intermediateOperations = [];

    private bool $used = false;

    /**
     * @param ConcurrentIterator<T> $source
     */
    public function __construct(ConcurrentIterator $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        if (!$this->used) {
            $this->source->dispose();
        }
    }

    public function concurrent(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    public function sequential(): self
    {
        $this->concurrency = 1;

        return $this;
    }

    public function ordered(): self
    {
        $this->ordered = true;

        return $this;
    }

    public function unordered(): self
    {
        $this->ordered = false;

        return $this;
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * @param \Closure(T, T): int $comparator
     *
     * @return T|null
     */
    public function min(\Closure $comparator): mixed
    {
        $min = null;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $min = $value;
            } else {
                $comparison = $comparator($min, $value);
                if ($comparison > 0) {
                    $min = $value;
                }
            }
        }

        return $min;
    }

    /**
     * @param \Closure(T, T): int $comparator
     *
     * @return T|null
     */
    public function max(\Closure $comparator): mixed
    {
        $max = null;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $max = $value;
            } else {
                $comparison = $comparator($max, $value);
                if ($comparison < 0) {
                    $max = $value;
                }
            }
        }

        return $max;
    }

    private function process(int $concurrency, \Closure $operator): self
    {
        if ($this->used) {
            throw new \Error('Can\'t add new operations once a terminal operation has been invoked');
        }

        $this->intermediateOperations[] = [$concurrency, $this->ordered, $operator];

        return $this;
    }

    /**
     * Maps values.
     *
     * @template R
     *
     * @param \Closure(T):R $map
     *
     * @return self<R>
     */
    public function map(\Closure $map): self
    {
        return $this->process($this->concurrency, fn (mixed $value) => [$map($value)]);
    }

    /**
     * Filters values.
     *
     * @param \Closure(T):bool $filter Keep value if {@code $filter} returns {@code true}.
     *
     * @return self<T>
     */
    public function filter(\Closure $filter): self
    {
        return $this->process($this->concurrency, fn (mixed $value) => $filter($value) ? [$value] : []);
    }

    /**
     * Invokes the given function each time a value is streamed through the pipeline to perform side effects.
     *
     * @param \Closure(T):void $tap
     *
     * @return self<T>
     */
    public function tap(\Closure $tap): self
    {
        return $this->process($this->concurrency, function (mixed $value) use ($tap) {
            $tap($value);

            return [$value];
        });
    }

    /**
     * @template R
     *
     * @param \Closure(R, T): R $accumulator
     * @param R $initial
     *
     * @return R
     */
    public function reduce(\Closure $accumulator, mixed $initial = null)
    {
        $result = $initial;

        foreach ($this as $value) {
            $result = $accumulator($result, $value);
        }

        return $result;
    }

    /**
     * Skip the first N items of the pipeline.
     *
     * @template T
     *
     * @param int $count
     *
     * @return self<T>
     */
    public function skip(int $count): self
    {
        return $this->process($this->concurrency, function (mixed $value) use ($count) {
            static $i = 0;

            if ($i++ < $count) {
                return [];
            }

            return [$value];
        });
    }

    /**
     * Skips values on the pipeline until {@code $predicate} returns {@code false}.
     *
     * All values are emitted afterwards without invoking {@code $predicate}.
     *
     * @param \Closure(T):bool $predicate
     *
     * @return self<T>
     */
    public function skipWhile(\Closure $predicate): self
    {
        return $this
            ->map(fn (mixed $value) => [$predicate($value), $value]) // TODO Don't evaluate if no longer needed
            ->process(1, function (array $value) {
                if ($value[0]) {
                    return [];
                }

                return [$value[1]];
            });
    }

    /**
     * Take only the first N items of the pipeline.
     *
     * @param int $count
     *
     * @return self<T>
     */
    public function take(int $count): self
    {
        return $this->process($this->concurrency, function (mixed $value) use ($count) {
            static $i = 0;

            if ($i++ < $count) {
                return [$value];
            }

            throw new DisposedException;
        });
    }

    /**
     * Takes values on the pipeline until {@code $predicate} returns {@code false}.
     *
     * @param \Closure(T):bool $predicate
     *
     * @return self<T>
     */
    public function takeWhile(\Closure $predicate): self
    {
        return $this
            ->map(fn (mixed $value) => [$predicate($value), $value]) // TODO Don't evaluate if no longer needed
            ->process(1, function (array $value) {
                if ($value[0]) {
                    return [$value[1]];
                }

                throw new DisposedException;
            });
    }

    /**
     * Invokes the given callback for each value emitted on the pipeline.
     *
     * @param \Closure(T):void $forEach
     *
     * @return void
     */
    public function forEach(\Closure $forEach): void
    {
        foreach ($this->tap($forEach) as $ignored) {
            // noop
        }
    }

    /**
     * Collects all items into an array.
     *
     * @return array<int, T>
     */
    public function toArray(): array
    {
        return \iterator_to_array($this);
    }

    /**
     * @return ConcurrentIterator<T>
     */
    public function getIterator(): ConcurrentIterator
    {
        if ($this->used) {
            throw new \Error('Pipelines can\'t be reused after a terminal operation');
        }

        $this->used = true;

        $source = $this->source;

        foreach ($this->intermediateOperations as [$concurrency, $ordered, $operation]) {
            $destination = new Source;

            if ($concurrency === 1) {
                async(static function () use ($source, $destination, $operation): void {
                    try {
                        foreach ($source as $value) {
                            foreach ($operation($value) as $emit) {
                                $destination->yield($emit);
                            }
                        }

                        $destination->complete();
                    } catch (DisposedException) {
                        $destination->complete();
                        $source->dispose();
                    } catch (\Throwable $e) {
                        $destination->error($e);
                        $source->dispose();
                    }
                });
            } else {
                $futures = [];

                $sequence = $ordered ? new Sequence : null;
                $position = 0;

                for ($i = 0; $i < $concurrency; $i++) {
                    $futures[] = async(function () use (
                        $source,
                        $destination,
                        $operation,
                        $sequence,
                        &$position
                    ): void {
                        foreach ($source as $value) {
                            $currentPosition = $position++;

                            if ($destination->isComplete()) {
                                return;
                            }

                            // The operation runs concurrently, but the emits are at the correct position
                            $result = $operation($value);

                            $sequence?->await($currentPosition);

                            foreach ($result as $emit) {
                                $destination->yield($emit);
                            }

                            $sequence?->arrive($currentPosition);
                        }
                    });
                }

                unset($position);

                async(static function () use ($futures, $source, $destination): void {
                    try {
                        Future\await($futures);
                        $destination->complete();
                    } catch (DisposedException) {
                        $destination->complete();
                        $source->dispose();
                    } catch (\Throwable $exception) {
                        $destination->error($exception);
                        $source->dispose();
                    }
                });
            }

            $source = $destination;
        }

        return $source instanceof Source ? new ConcurrentSourceIterator($source) : $source;
    }

    public function dispose(): void
    {
        $this->source->dispose();
    }
}
