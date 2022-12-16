<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class ReduceTest extends AsyncTestCase
{
    public function testReduce(): void
    {
        $values = [1, 2, 3, 4, 5];

        $pipeline = Pipeline::fromIterable($values);

        $result = $pipeline->reduce(fn (int $carry, int $emitted) => $carry + $emitted, 0);

        self::assertSame(\array_sum($values), $result);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $source->pushAsync(1)->ignore();
        $source->error($exception);

        $this->expectExceptionObject($exception);

        $source->pipe()->reduce($this->createCallback(1));
    }
}
