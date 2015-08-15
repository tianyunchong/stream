<?php
namespace Icicle\Stream;

use Icicle\Stream\Exception\UnwritableException;

trait PipeTrait
{
    use ParserTrait;

    /**
     * @see \Icicle\Stream\ReadableStreamInterface::read()
     *
     * @coroutine
     *
     * @param int $length
     * @param string|int|null $byte
     * @param float|int $timeout
     *
     * @return \Generator
     */
    abstract public function read($length = 0, $byte = null, $timeout = 0);

    /**
     * @see \Icicle\Stream\ReadableStreamInterface::isReadable()
     *
     * @return bool
     */
    abstract public function isReadable();

    /**
     * @see \Icicle\Stream\ReadableStreamInterface::pipe()
     *
     * @coroutine
     *
     * @param \Icicle\Stream\WritableStreamInterface $stream
     * @param bool $end
     * @param int $length
     * @param string|int|null $byte
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve int
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function pipe(WritableStreamInterface $stream, $end = true, $length = 0, $byte = null, $timeout = 0)
    {
        if (!$stream->isWritable()) {
            throw new UnwritableException('The stream is not writable.');
        }

        $length = $this->parseLength($length);
        $byte = $this->parseByte($byte);

        $bytes = 0;

        try {
            do {
                $data = (yield $this->read($length, $byte, $timeout));

                $count = strlen($data);
                $bytes += $count;

                yield $stream->write($data, $timeout);
            } while ($this->isReadable()
                && $stream->isWritable()
                && (null === $byte || $data[$count - 1] !== $byte)
                && (0 === $length || 0 < $length -= $count)
            );
        } catch (\Exception $exception) {
            if ($end && $stream->isWritable()) {
                yield $stream->end();
            }
            throw $exception;
        }

        if ($end && $stream->isWritable()) {
            yield $stream->end();
        }

        yield $bytes;
    }
}
