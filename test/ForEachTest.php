<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;

class ForEachTest extends AsyncTestCase
{
    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Queue;

        $source->enqueue(1)->ignore();
        $source->error($exception);

        $this->expectExceptionObject($exception);

        $source->pipe()->forEach($this->createCallback(1));
    }

    public function testReduce(): void
    {
        $values = [1, 2, 3, 4, 5];

        $pipeline = Pipeline::fromIterable($values);

        $pipeline->forEach($this->createCallback(\count($values)));
    }
}
