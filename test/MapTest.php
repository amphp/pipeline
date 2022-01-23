<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class MapTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Emitter;

        /** @noinspection PhpUnusedLocalVariableInspection */
        $pipeline = $source->pipe()->map($this->createCallback(0));

        $source->complete();
    }

    public function testValuesEmitted(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $generator = Pipeline\fromIterable(function () use ($values) {
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
        $generator = Pipeline\fromIterable(function () use ($values) {
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

    /**
     * @depends testValuesEmitted
     */
    public function testOnNextCallbackThrows(): void
    {
        $values = [1, 2, 3];
        $exception = new TestException;

        $generator = Pipeline\fromIterable(function () use ($values) {
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
        $source = new Emitter;

        $iterator = $source->pipe()->map($this->createCallback(0))->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
