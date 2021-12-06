<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class EachTest extends AsyncTestCase
{
    public function testPipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $source->emit(1)->ignore();
        $source->error($exception);

        $this->expectExceptionObject($exception);

        Pipeline\each($source->asPipeline(), $this->createCallback(1));
    }

    public function testReduce(): void
    {
        $values = [1, 2, 3, 4, 5];

        $pipeline = Pipeline\fromIterable($values);

        Pipeline\each($pipeline, $this->createCallback(\count($values)));
    }
}
