<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\delay;

class ConcurrentTest extends AsyncTestCase
{
    public function testNoValuesEmitted(): void
    {
        $source = new Queue;

        $pipeline = $source->pipe()
            ->concurrent(3)
            ->map($this->createCallback(0));

        $source->complete();

        self::assertSame(0, $pipeline->count());
    }

    public function testConcurrencyOrdered(): void
    {
        $range = \range(0, 100);

        $source = Pipeline::fromIterable($range);

        $results = $source->concurrent(3)
            ->tap(fn (int $value) => delay(\random_int(0, 10) / 1000))
            ->toArray();

        self::assertSame($range, $results);
    }

    public function testConcurrencyUnordered(): void
    {
        $range = \range(0, 100);

        $source = Pipeline::fromIterable($range);

        $results = $source->concurrent(3)
            ->unordered()
            ->tap(fn (int $value) => delay(\random_int(0, 10) / 1000))
            ->toArray();

        self::assertNotSame($range, $results);

        foreach ($range as $value) {
            self::assertContains(needle: $value, haystack: $results);
        }
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $pipeline = $source->pipe()
            ->concurrent(3)
            ->tap($this->createCallback(1));

        $source->pushAsync(1)->ignore();
        $source->error($exception);

        $iterator = $pipeline->getIterator();

        self::assertTrue($iterator->continue());
        self::assertSame(1, $iterator->getValue());

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }
}
