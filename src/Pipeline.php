<?php

namespace Amp\Pipeline;

use Amp\Future;
use Amp\Pipeline\Internal\ConcurrentSourceIterator;
use Amp\Pipeline\Internal\Sequence;
use Amp\Pipeline\Internal\Source;
use Revolt\EventLoop;
use function Amp\async;

/**
 * A pipeline is an asynchronous set of ordered values.
 *
 * @template T
 * @template-implements \IteratorAggregate<int, T>
 */
final class Pipeline implements \IteratorAggregate
{
    private static \stdClass $stop;

    /**
     * Creates a pipeline from the given closure returning an iterable.
     *
     * @template Ts
     *
     * @param \Closure():iterable<array-key, Ts> $iterable Elements to emit.
     *
     * @return self<Ts>
     */
    public static function fromClosure(\Closure $closure): Pipeline
    {
        $iterable = $closure();

        if (!\is_iterable($iterable)) {
            throw new \TypeError('Return value of argument #1 ($iterable) must be of type iterable, ' . \get_debug_type($iterable) . ' returned');
        }

        return self::fromIterable($iterable);
    }

    /**
     * Creates a pipeline from the given iterable.
     *
     * @template Ts
     *
     * @param iterable<array-key, Ts> $iterable
     *
     * @return self<Ts>
     */
    public static function fromIterable(iterable $iterable): self
    {
        if ($iterable instanceof self) {
            return $iterable;
        }

        if (\is_array($iterable)) {
            return new self(new ConcurrentArrayIterator($iterable));
        }

        $source = new Source();

        EventLoop::queue(static function () use ($iterable, $source): void {
            try {
                foreach ($iterable as $value) {
                    $source->yield($value);
                }

                $source->complete();
            } catch (\Throwable $exception) {
                $source->error($exception);
            } finally {
                $source->dispose();
            }
        });

        return new Pipeline(new ConcurrentSourceIterator($source));
    }

    /**
     * Concatenates the given pipelines into a single pipeline in sequential order.
     *
     * The prior pipeline must complete before values are taken from any subsequent pipelines.
     *
     * @template Ts
     *
     * @param Pipeline<Ts>[] $pipelines
     *
     * @return self<Ts>
     */
    public static function concat(array $pipelines): self
    {
        foreach ($pipelines as $key => $pipeline) {
            if (!$pipeline instanceof self) {
                throw new \TypeError(\sprintf(
                    'Argument #1 ($pipelines) must be of type array<%s>, %s given at key %s',
                    self::class,
                    \get_debug_type($pipeline),
                    $key
                ));
            }
        }

        return new self(new ConcurrentConcatIterator(
            \array_map(static fn (self $pipeline) => $pipeline->getIterator(), $pipelines)
        ));
    }

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
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        self::$stop ??= new \stdClass;

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
     * @template R
     *
     * @param \Closure(T, T): int $comparator
     * @param R $default
     *
     * @return T|R
     */
    public function min(\Closure $comparator, mixed $default = null): mixed
    {
        $min = $default;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $min = $value;
            } else {
                /** @var T $min */
                $comparison = $comparator($min, $value);
                if ($comparison > 0) {
                    $min = $value;
                }
            }
        }

        return $min;
    }

    /**
     * @template R
     *
     * @param \Closure(T, T): int $comparator
     * @param R $default
     *
     * @return T|R
     */
    public function max(\Closure $comparator, mixed $default = null): mixed
    {
        $max = $default;
        $first = true;

        foreach ($this as $value) {
            if ($first) {
                $first = false;
                $max = $value;
            } else {
                /** @var T $max */
                $comparison = $comparator($max, $value);
                if ($comparison < 0) {
                    $max = $value;
                }
            }
        }

        return $max;
    }

    /**
     * @param \Closure(T): bool $predicate
     *
     * @return bool
     */
    public function allMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if (!$value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \Closure(T): bool $predicate
     *
     * @return bool
     */
    public function anyMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if ($value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Closure(T): bool $predicate
     *
     * @return bool
     */
    public function noneMatch(\Closure $predicate): bool
    {
        foreach ($this->map($predicate) as $value) {
            if ($value) {
                return false;
            }
        }

        return true;
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
        $sequence = new Sequence;
        $skipping = true;

        return $this->process(
            $this->concurrency,
            function (mixed $value, int $position) use ($sequence, $predicate, &$skipping) {
                if (!$skipping) {
                    return [$value];
                }

                $predicateResult = $predicate($value);

                $sequence->start($position);

                /** @psalm-suppress RedundantCondition */
                if ($skipping && $predicateResult) {
                    $sequence->end($position);
                    return [];
                }

                $skipping = false;
                $sequence->end($position);

                return [$value];
            }
        );
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

            return [self::$stop];
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
        $sequence = new Sequence;
        $taking = true;

        return $this->process(
            $this->concurrency,
            function (mixed $value, int $position) use ($sequence, $predicate, &$taking) {
                if (!$taking) {
                    return false;
                }

                $predicateResult = $predicate($value);

                $sequence->start($position);

                /** @psalm-suppress RedundantCondition */
                if ($taking && $predicateResult) {
                    $sequence->end($position);
                    return [$value];
                }

                $taking = false;
                $sequence->end($position);

                return [self::$stop];
            }
        );
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
        return \iterator_to_array($this, false);
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
                        foreach ($source as $position => $value) {
                            $iterable = $operation($value, $position);
                            foreach ($iterable as $emit) {
                                if ($emit === self::$stop) {
                                    break 2;
                                }

                                $destination->yield($emit);
                            }
                        }

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

                for ($i = 0; $i < $concurrency; $i++) {
                    $futures[] = async(function () use (
                        $source,
                        $destination,
                        $operation,
                        $sequence
                    ): void {
                        foreach ($source as $position => $value) {
                            if ($destination->isComplete()) {
                                return;
                            }

                            // The operation runs concurrently, but the emits are at the correct position
                            $iterable = $operation($value, $position);

                            $sequence?->start($position);

                            foreach ($iterable as $emit) {
                                /** @psalm-suppress TypeDoesNotContainType */
                                if ($emit === self::$stop || $destination->isComplete()) {
                                    break 2;
                                }

                                $destination->yield($emit);
                            }

                            $sequence?->end($position);
                        }
                    });
                }

                async(static function () use ($futures, $source, $destination): void {
                    try {
                        Future\await($futures);
                        $destination->complete();
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
