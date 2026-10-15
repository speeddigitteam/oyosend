<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;

/**
 * How one video is cut for TikTok's `FILE_UPLOAD` (Media Transfer Guide): every chunk is 5 MB to 64 MB,
 * a video up to one chunk is sent whole, `total_chunk_count` is the size divided by the chunk size rounded
 * down, and the last chunk carries the remainder (so it may be up to twice the chunk size, under 128 MB).
 * At most 1000 chunks.
 */
final readonly class TikTokChunkPlan
{
    public const int MIN_CHUNK = 5 * 1024 * 1024;

    public const int MAX_CHUNK = 64 * 1024 * 1024;

    public const int MAX_CHUNKS = 1000;

    public function __construct(
        public int $size,
        public int $chunkSize,
        public int $count,
    ) {}

    /** @throws PublishFailed when the file is empty or too large to cut into 1000 chunks */
    public static function for(int $size, int $preferredChunk): self
    {
        if ($size < 1) {
            throw PublishFailed::rejected(__('The video file is empty.'), 'media_missing');
        }

        $chunk = max(self::MIN_CHUNK, min(self::MAX_CHUNK, $preferredChunk));

        if ($size <= $chunk) {
            return new self($size, $size, 1);
        }

        if (intdiv($size, $chunk) > self::MAX_CHUNKS) {
            $chunk = (int) ceil($size / self::MAX_CHUNKS);

            if ($chunk > self::MAX_CHUNK) {
                throw PublishFailed::rejected(__('The video is larger than TikTok accepts.'), 'video_too_large');
            }
        }

        return new self($size, $chunk, intdiv($size, $chunk));
    }

    /**
     * Offset and length of one chunk.
     *
     * @return array{0: int, 1: int}
     */
    public function range(int $index): array
    {
        $start = $index * $this->chunkSize;
        $length = $index >= $this->count - 1 ? $this->size - $start : $this->chunkSize;

        return [$start, $length];
    }

    /** The chunk that starts at a confirmed byte offset. */
    public function indexAt(int $offset): int
    {
        return min($this->count, intdiv(max(0, $offset), max(1, $this->chunkSize)));
    }
}
