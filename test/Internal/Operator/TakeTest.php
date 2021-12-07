<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;

class TakeTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $count = 2;
        $values = [1, 2, 3, 4];
        $pipeline = Pipeline\fromIterable($values)->pipe(Pipeline\take($count));

        $emitted = 0;
        while (null !== $value = $pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $value);
        }

        self::assertSame($count, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->pipe(Pipeline\take(2));

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
