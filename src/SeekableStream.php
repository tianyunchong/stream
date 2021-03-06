<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface SeekableStream extends Stream
{
    /**
     * @coroutine
     *
     * Moves the pointer to a new position in the stream.
     *
     * @param int $offset Number of bytes to seek. Usage depends on value of $whence.
     * @param int $whence Values identical to $whence values for fseek().
     * @param float|int $timeout Number of seconds until the operation fails and the stream is closed and the promise
     *     is rejected with a TimeoutException. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int New pointer position.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the whence value is invalid.
     * @throws \Icicle\Stream\Exception\InvalidOffsetException If the new offset would be outside the stream.
     * @throws \Icicle\Stream\Exception\UnseekableException If the stream is no longer seekable (due to being closed or
     *     for another reason).
     */
    public function seek(int $offset, int $whence = SEEK_SET, float $timeout = 0): \Generator;

    /**
     * Current pointer position. Value returned may not reflect the future pointer position if a read, write, or seek
     * operation is pending.
     *
     * @return int
     */
    public function tell(): int;

    /**
     * Returns the total length of the stream if known, otherwise -1. Value returned may not reflect a pending write
     * operation.
     *
     * @return int
     */
    public function getLength();
}
