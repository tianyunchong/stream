<?php
namespace Icicle\Tests\Stream;

use Exception;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\Stream;
use Icicle\Stream\WritableStreamInterface;

class StreamTest extends TestCase
{
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const HWM = 16384;

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }
    
    /**
     * @param int $hwm
     *
     * @return \Icicle\Stream\Stream[] Same stream instance for readable and writable.
     */
    public function createStreams($hwm = self::CHUNK_SIZE)
    {
        $stream = new Stream($hwm);

        return [$stream, $stream];
    }

    public function testRead()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $readable->close();

        $this->assertFalse($readable->isReadable());

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        Loop\tick(false);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testSimultaneousRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read());
        Loop\tick(false);

        $promise2 = new Coroutine($readable->read());
        Loop\tick(false);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceof(BusyError::class));

        $promise2->done($this->createCallback(0), $callback);

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $length = floor(strlen(StreamTest::WRITE_STRING) / 2);

        $promise = new Coroutine($readable->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, $length, $length)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $promise = new Coroutine($readable->read(-1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testCancelRead()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());
        Loop\tick(false);

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());
        Loop\tick(false);

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadOnEmptyStream()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read()); // Nothing to read on this stream.

        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadOnEmptyStream
     */
    public function testDrainThenRead()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $string = "This is a string to write.\n";

        $promise2 = new Coroutine($writable->write($string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise2->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 5;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToMultibyteString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 5;
        $length = 3;
        $string = substr(StreamTest::WRITE_STRING, $offset, $length);

        $promise = new Coroutine($readable->read(0, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToNoMatchInStream()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $char = '~';

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));
        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadTo
     */
    public function testReadToEmptyString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $promise = new Coroutine($readable->read(0, ''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $readable->close();

        $this->assertFalse($readable->isReadable());

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\0"));
        Loop\tick(false);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testSimultaneousReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read(0, "\0"));
        Loop\tick(false);

        $promise2 = new Coroutine($readable->read(0, "\0"));
        Loop\tick(false);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceof(BusyError::class));

        $promise2->done($this->createCallback(0), $callback);

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        $offset = 10;
        $length = 5;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read($length, $char));
        Loop\tick();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, $length, $offset - $length + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 5;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(-1, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testCancelReadTo()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $char = substr(StreamTest::WRITE_STRING, 0, 1);

        $promise = new Coroutine($readable->read(0, $char));
        Loop\tick(false);

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));
        Loop\tick(false);

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($char));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToOnEmptyStream()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\n")); // Nothing to read on this stream.
        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadToOnEmptyStream
     */
    public function testDrainThenReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $char = "\n";

        $promise = new Coroutine($readable->read());

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $string1 = "This is a string to write.\n";
        $string2 = "This part should not be read.\n";

        new Coroutine($writable->write($string1 . $string2));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadAfterReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 5;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(StreamTest::WRITE_STRING, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadAfterCancelledReadTo()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $offset = 5;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));
        Loop\tick();

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());
        Loop\tick();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testPipe()
    {
        list($readable, $writable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $count = 0;
                return 3 >= ++$count;
            }));

        $mock->expects($this->exactly(3))
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(StreamTest::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $promise = new Coroutine($readable->pipe($mock));
        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\tick();

        $this->assertTrue($promise->isPending());
        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\tick();

        $this->assertTrue($promise->isPending());
        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\tick();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
        $this->assertSame(strlen(StreamTest::WRITE_STRING) * 3, $promise->wait());
    }

    /**
     * @depends testPipe
     */
    public function testPipeOnUnwritableStream()
    {
        list($readable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(false));

        $promise = new Coroutine($readable->pipe($mock));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf('Icicle\Stream\Exception\UnwritableException'));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            return yield strlen(StreamTest::WRITE_STRING);
        };
        $stream->write(StreamTest::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldBeCalled();

        $promise = new Coroutine($readable->pipe($stream->reveal(), true));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->once())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, true));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeDoNotEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(StreamTest::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeDoNotEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeCancel()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            return yield strlen(StreamTest::WRITE_STRING);
        };
        $stream->write(StreamTest::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldNotBeCalled();

        $promise = new Coroutine($readable->pipe($stream->reveal()));

        $exception = new Exception();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $promise->cancel($exception);

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $this->assertSame(substr(StreamTest::WRITE_STRING, 0, $length), $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, $length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($length));

        $promise->done($callback);

        Loop\tick();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->exactly(2))
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, strlen(StreamTest::WRITE_STRING)));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        Loop\tick();

        $this->assertFalse($promise->isPending());
    }

    /**
     * @depends testPipeWithLength
     */
    public function testPipeWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $i = 0;
                return !$i++;
            }));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, -1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 10;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset) {
                $this->assertSame(substr(StreamTest::WRITE_STRING, 0, $offset + 1), $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->once())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, true, 0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset + 1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToOnUnwritableStream()
    {
        list($readable, $writable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(false));

        $promise = new Coroutine($readable->pipe($mock, true, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToMultibyteString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $offset = 5;
        $length = 3;
        $string = substr(StreamTest::WRITE_STRING, $offset, $length);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset) {
                $this->assertSame(substr(StreamTest::WRITE_STRING, 0, $offset + 1), $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $promise = new Coroutine($readable->pipe($mock, true, 0, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset + 1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            return yield strlen(StreamTest::WRITE_STRING);
        };
        $stream->write(StreamTest::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldBeCalled();

        $promise = new Coroutine($readable->pipe($stream->reveal(), true, 0, '!'));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->once())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, true, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToDoNotEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(StreamTest::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, 0, '!'));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToDoNotEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $length = 8;
        $offset = 10;
        $char = substr(StreamTest::WRITE_STRING, $offset, 1);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $this->assertSame(substr(StreamTest::WRITE_STRING, 0, $length), $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, $length, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($length));

        $promise->done($callback);

        Loop\tick();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset, $length) {
                $this->assertSame(substr(StreamTest::WRITE_STRING, $length, $offset - $length + 1), $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, strlen(StreamTest::WRITE_STRING), $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset - $length + 1));

        $promise->done($callback);

        Loop\tick();

        $this->assertFalse($promise->isPending());
    }

    /**
     * @depends testPipeToWithLength
     */
    public function testPipeToWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $i = 0;
                return !$i++;
            }));

        $mock->expects($this->once())
            ->method('write');

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, -1, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testRead
     */
    public function testReadWithTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, null, StreamTest::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\0", StreamTest::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(StreamTest::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, 0, null, StreamTest::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeTimeout
     */
    public function testPipeWithLengthTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(
            $readable->pipe($mock, false, strlen(StreamTest::WRITE_STRING) + 1, null, StreamTest::TIMEOUT)
        );

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(StreamTest::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine($readable->pipe($mock, false, 0, '!', StreamTest::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeToTimeout
     */
    public function testPipeToWithLengthTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $generator = function () use ($data) {
                    return yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(
            $readable->pipe($mock, false, strlen(StreamTest::WRITE_STRING) + 1, '!', StreamTest::TIMEOUT)
        );

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    public function testWrite()
    {
        list($readable, $writable) = $this->createStreams();

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($writable->write($string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $writable->close();

        $this->assertFalse($writable->isWritable());

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteEmptyString()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($writable->write('0'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(1));

        $promise->done($callback);

        $promise = new Coroutine($readable->read(1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo('0'));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testEnd()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->end(StreamTest::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($writable->isOpen());

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isWritable());
        $this->assertFalse($writable->isOpen());
    }

    /**
     * @depends testWrite
     */
    public function testWriteTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING, StreamTest::TIMEOUT));
            Loop\tick();
        } while (!$promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }


    public function testEndWithPendingRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());
        Loop\tick();

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        $promise = new Coroutine($writable->end(StreamTest::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();


        $this->assertFalse($readable->isReadable());
    }

    /**
     * @depends testEndWithPendingRead
     */
    public function testEndWithPendingReadWritingNoData()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());
        Loop\tick();

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        $promise = new Coroutine($writable->end());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isWritable());
        $this->assertFalse($readable->isReadable());
    }

    /**
     * @depends testWrite
     */
    public function testCloseAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(StreamTest::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $writable->close();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(StreamTest::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $buffer = '';

        for ($i = 0; $i < StreamTest::CHUNK_SIZE + 1; ++$i) {
            $buffer .= '1';
        }

        $promise = new Coroutine($writable->write($buffer));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($buffer)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testEnd
     * @depends testWriteAfterPendingWrite
     */
    public function testEndAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(StreamTest::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->end(StreamTest::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
            Loop\tick();
        }

        $this->assertFalse($writable->isWritable());
    }

    /**
     * @depends testWriteEmptyString
     * @depends testWriteAfterPendingWrite
     */
    public function testWriteEmptyStringAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(StreamTest::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWriteAfterEof()
    {
        list($readable, $writable) = $this->createStreams(StreamTest::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        // Extra write to ensure queue is not empty when write callback is called.
        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $readable->close(); // Close readable stream.

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\run();
    }
}