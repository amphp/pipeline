<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class TakeWhileTest extends AsyncTestCase
{
    public function testAllValuesTrue(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testSomeValuesTrue(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3, 4, 5])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testSomeValuesConcurrentLazy(): void
    {
        $pipeline = Pipeline::fromIterable(function () {
            print '.';
            yield 1;
            print '.';
            yield 2;
            print '.';
            yield 3;
            print '.';
        })->concurrent(2)->takeWhile(fn ($value) => $value < 2);

        $this->expectOutputString('...');

        self::assertSame([1], $pipeline->toArray());
    }

    public function testSomeValuesTrueAfterFalse(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3, 4, 5, 4, 3, 2, 1])->takeWhile(fn ($value) => $value < 3);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $iterator = $source->pipe()->takeWhile(fn ($value) => $value < 3)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPredicateThrows(): void
    {
        $exception = new TestException;

        $iterator = Pipeline::fromIterable([1, 2, 3])->takeWhile(fn ($value) => throw $exception)->getIterator();

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
