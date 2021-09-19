<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class ReduceTest extends AsyncTestCase
{
    public function testReduce(): void
    {
        $values = [1, 2, 3, 4, 5];

        $pipeline = Pipeline\fromIterable($values);

        $result = Pipeline\reduce($pipeline, fn (int $carry, int $emitted) => $carry + $emitted, 0);

        self::assertSame(\array_sum($values), $result);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Subject;

        $source->emit(1);
        $source->error($exception);

        $this->expectExceptionObject($exception);

        $result = Pipeline\reduce($source->asPipeline(), $this->createCallback(1));
    }
}