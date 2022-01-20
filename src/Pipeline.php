<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Pipeline\Internal\Source;
use Revolt\EventLoop;
use function Amp\async;

/**
 * A pipeline is an asynchronous set of ordered values.
 *
 * @template T
 * @template-implements \IteratorAggregate<int, T>
 */
final class Pipeline implements ConcurrentIterator, \IteratorAggregate
{
    private ConcurrentIterator $source;
    private int $concurrency = 1;

    /**
     * @param ConcurrentIterator<T> $source
     */
    public function __construct(ConcurrentIterator $source)
    {
        $this->source = $source;
    }

    public function __destruct()
    {
        $this->source->dispose();
    }

    public function concurrent(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        // TODO Return new instance?
        return $this;
    }

    public function count(): int
    {
        $count = 0;

        // TODO Concurrency?
        foreach ($this as $ignored) {
            $count++;
        }

        return $count;
    }

    private function process(int $concurrency, \Closure $operator): self
    {
        $destination = new Source($concurrency);

        if ($concurrency === 1) {
            try {
                foreach ($this->source as $value) {
                    foreach ($operator($value) as $emit) {
                        $destination->emit($emit);
                    }
                }
            } catch (\Throwable $e) {
                $destination->error($e);
            }
        } else {
            $futures = [];

            for ($i = 0; $i < $concurrency; $i++) {
                $futures[] = async(function () use ($destination, $operator): void {
                    foreach ($this->source as $value) {
                        if ($destination->isComplete()) {
                            return;
                        }

                        foreach ($operator($value) as $emit) {
                            $destination->emit($emit);
                        }
                    }
                });
            }

            EventLoop::queue(function () use ($futures, $destination): void {
                try {
                    Future\await($futures);
                    $destination->complete();
                } catch (\Throwable $exception) {
                    $destination->error($exception);
                    $this->source->dispose();
                }
            });
        }

        return new self($destination);
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

        // TODO Concurrency?
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

            throw new CancelledException;
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

                throw new CancelledException;
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
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        while ($this->source->continue()) {
            yield $this->source->get();
        }
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
}
