<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Text;

use Icicle\Stream\StreamInterface;
use Icicle\Stream\Structures\Buffer;
use Icicle\Stream\WritableStreamInterface;

/**
 * Writes text to a stream.
 */
class TextWriter implements StreamInterface
{
    const DEFAULT_BUFFER_SIZE = 16384;

    /**
     * @var \Icicle\Stream\WritableStreamInterface The stream to write to.
     */
    private $stream;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;

    /**
     * @var bool Indicates if the buffer should be flushed on every write.
     */
    private $autoFlush = false;

    /**
     * @var int The max buffer size in bytes.
     */
    private $bufferSize;

    /**
     * Creates a new stream writer for a given stream.
     *
     * @param \Icicle\Stream\WritableStreamInterface $stream The stream to write to.
     * @param bool $autoFlush Indicates if the buffer should be flushed on every write.
     * @param int $bufferSize The max buffer size in bytes.
     */
    public function __construct(
        WritableStreamInterface $stream,
        $autoFlush = false,
        $bufferSize = self::DEFAULT_BUFFER_SIZE
    ) {
        $this->stream = $stream;
        $this->buffer = new Buffer();
        $this->autoFlush = (bool) $autoFlush;
        $this->bufferSize = (int) $bufferSize;
    }

    /**
     * Gets the underlying stream.
     *
     * @return \Icicle\Stream\WritableStreamInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Determines if the stream is still open.
     *
     * @return bool
     */
    public function isOpen()
    {
        return $this->stream->isOpen();
    }

    /**
     * Closes the stream writer and the underlying stream.
     *
     * The buffer will not be automatically flushed. You should call `flush()` before
     * closing to ensure all data is written to the stream.
     */
    public function close()
    {
        $this->stream->close();
    }

    /**
     * @coroutine
     *
     * Flushes the contents of the internal buffer to the underlying stream.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function flush($timeout = 0)
    {
        if (!$this->buffer->isEmpty()) {
            yield $this->stream->write($this->buffer->drain(), $timeout);
        }
    }

    /**
     * @coroutine
     *
     * Writes a value to the stream.
     *
     * The given value will be coerced to a string before being written. The resulting
     * string will be written to the internal buffer; if the buffer is full, the entire
     * buffer will be flushed to the stream.
     *
     * @param mixed $text A printable value that can be coerced to a string.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function write($text, $timeout = 0)
    {
        $this->buffer->push((string) $text);

        if ($this->autoFlush || $this->buffer->getLength() > $this->bufferSize) {
            yield $this->flush($timeout);
        }
    }

    /**
     * @coroutine
     *
     * Writes a value to the stream and then terminates the line.
     *
     * The given value will be coerced to a string before being written.
     *
     * @param mixed $text A printable value that can be coerced to a string.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function writeLine($text, $timeout = 0)
    {
        yield $this->write((string) $text . PHP_EOL, $timeout);
    }

    /**
     * @coroutine
     *
     * Writes a formatted string to the stream.
     *
     * Accepts a format string followed by a series of mixed values. The string
     * will be formatted in accordance with the specification of the built-in
     * function `printf()`.
     *
     * @param string $format The format string.
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     *
     * @see http://php.net/prinf
     */
    public function printf($format /*, ...$args */)
    {
        $formatted = call_user_func_array('sprintf', func_get_args());
        yield $this->write($formatted);
    }

    /**
     * @coroutine
     *
     * Writes a formatted string to the stream and then terminates the line.
     *
     * Accepts a format string followed by a series of mixed values. The string
     * will be formatted in accordance with the specification of the built-in
     * function `printf()`.
     *
     * @param string $format The format string.
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     *
     * @see http://php.net/prinf
     */
    public function printLine($format /*, ...$args */)
    {
        $formatted = call_user_func_array('sprintf', func_get_args());
        yield $this->write($formatted . PHP_EOL);
    }
}
