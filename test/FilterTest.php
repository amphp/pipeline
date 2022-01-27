<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class FilterTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Queue;

        $pipeline = $source->pipe()->filter($this->createCallback(0));

        $source->complete();

        self::assertSame(0, $pipeline->count());
    }

    public function testValuesEmitted(): void
    {
        $count = 0;
        $values = [1, 2, 3];
        $expected = [1, 3];
        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->filter(function ($value) use (&$count): bool {
            ++$count;
            return (bool) ($value & 1);
        });

        self::assertSame($expected, $pipeline->toArray());
        self::assertSame(3, $count);
    }

    /**
     * @depends testValuesEmitted
     */
    public function testCallbackThrows(): void
    {
        $values = [1, 2, 3];
        $exception = new TestException;
        $generator = Pipeline::fromIterable(function () use ($values) {
            foreach ($values as $value) {
                yield $value;
            }
        });

        $pipeline = $generator->filter(fn () => throw $exception);

        $this->expectExceptionObject($exception);

        $pipeline->toArray();
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $pipeline = $source->pipe()->filter($this->createCallback(0));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $pipeline->toArray();
    }
}
