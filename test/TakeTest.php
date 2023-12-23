<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\delay;

class TakeTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3, 4])
            ->take(2);

        self::assertSame([1, 2], $pipeline->toArray());
    }

    public function testCompleteBeforeSourceCompletes()
    {
        $count = 3;
        $this->setTimeout(0.1 * $count + 0.1);

        $emitted = Pipeline::fromIterable(function () use ($count): \Generator {
            for ($i = 0; $i < $count; ++$i) {
                delay(0.1);
                yield $i;
            }
            delay(1);
        })->take($count)->toArray();

        self::assertSame(\range(0, $count - 1), $emitted);
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException();
        $source = new Queue();

        $iterator = $source->pipe()->take(2)->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
