<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class TakeTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3, 4])
            ->take(2);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $iterator = $source->pipe()->take(2)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
