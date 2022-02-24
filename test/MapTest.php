<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\async;
use function Amp\delay;

class MapTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Queue;

        /** @noinspection PhpUnusedLocalVariableInspection */
        $pipeline = $source->pipe()->map($this->createCallback(0));

        $source->complete();
    }

    public function testValuesEmitted(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->map(function ($value) use (&$count): int {
            ++$count;
            return $value + 1;
        });

        self::assertSame([2, 3, 4], $pipeline->toArray());
        self::assertSame(3, $count);
    }

    public function testValuesEmittedConcurrent(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->concurrent(2)->map(function ($value) use (&$count): int {
            ++$count;
            return $value + 1;
        });

        self::assertSame([2, 3, 4], $pipeline->toArray());
        self::assertSame(3, $count);
    }

    public function testMapFilterConcurrentOrdering(): void
    {
        $count = 0;
        $values = [1, 2, 3, 4, 5, 6, 7, 8, 9];
        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->concurrent(4)->map(function ($value) use (&$count): int {
            ++$count;
            return $value + 1;
        });

        self::assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $pipeline->toArray());
    }

    /**
     * @depends testValuesEmitted
     */
    public function testOnNextCallbackThrows(): void
    {
        $values = [1, 2, 3];
        $exception = new TestException;

        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->map(fn () => throw $exception)->getIterator();

        $this->expectExceptionObject($exception);

        $pipeline->continue();
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $iterator = $source->pipe()->map($this->createCallback(0))->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPipelineFailsConcurrent1(): void
    {
        $this->expectOutputString('abc');

        $iterator = Pipeline::generate(static fn () => null)->concurrent(3)->map(function () {
            static $i = 0;

            $current = ++$i;

            if ($current === 3) {
                print 'b';

                throw new TestException('3');
            }

            if ($current === 2) {
                print 'a';

                delay(0.5);

                print 'c';

                throw new TestException('2');
            }

            delay(1);

            return $current;
        })->getIterator();

        $future1 = async(function () use ($iterator) {
            $iterator->continue();

            return $iterator->getValue();
        });

        $future2 = async(function () use ($iterator) {
            $iterator->continue();

            return $iterator->getValue();
        });

        $future3 = async(function () use ($iterator) {
            $iterator->continue();

            return $iterator->getValue();
        });

        self::assertSame(1, $future1->await());

        try {
            $future2->await();
            self::fail('Missing exception');
        } catch (TestException $e) {
            self::assertSame('2', $e->getMessage());
        }

        try {
            $future3->await();
            self::fail('Missing exception');
        } catch (TestException $e) {
            self::assertSame('2', $e->getMessage()); // Pipeline already failed with exception 2.
        }
    }

    public function testPipelineFailsConcurrent2(): void
    {
        $this->expectOutputString('1234');

        $failAt = 5;
        $iterator = Pipeline::generate(static fn () => null)
            ->concurrent(10)
            ->map(function () use ($failAt): int {
                static $i = 0;

                $current = ++$i;

                if ($current === $failAt) {
                    throw new TestException('3');
                }

                delay(0.1);

                return $current;
            })
            ->tap(static fn (int $value) => print $value)
            ->getIterator();

        $futures = [];

        for ($i = 0; $i < $failAt; $i++) {
            $futures[] = async(function () use ($iterator) {
                self::assertTrue($iterator->continue());

                return $iterator->getValue();
            });
        }

        for ($i = 0; $i < $failAt - 1; $i++) {
            self::assertSame($i + 1, $futures[$i]->await());
        }

        try {
            $futures[$i]->await();
            self::fail('Missing exception');
        } catch (TestException $e) {
            self::assertSame('3', $e->getMessage());
        }
    }
}
