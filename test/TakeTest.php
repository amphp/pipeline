<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class TakeTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $count = 2;
        $values = [1, 2, 3, 4];
        $pipeline = Pipeline\fromIterable($values)->take($count)->getIterator();

        $emitted = 0;
        while ($pipeline->continue()) {
            $emitted++;
            self::assertSame(\array_shift($values), $pipeline->get());
        }

        self::assertSame($count, $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $iterator = $source->pipe()->take(2)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
