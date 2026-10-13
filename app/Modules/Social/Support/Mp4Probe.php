<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

/**
 * Reads length and picture size from the header boxes of an MP4 / QuickTime file without ffmpeg.
 *
 * Only the `moov` box is read (the `mdat` payload is skipped by seeking), every size is checked against
 * the file and the parse is bounded, so a malformed or hostile file ends in `null`, never in a loop or a
 * large allocation. `null` means "could not be read": callers show an honest "unknown", not zero.
 */
final class Mp4Probe
{
    /** Largest `moov` box read into memory. */
    private const int MAX_MOOV_BYTES = 16 * 1024 * 1024;

    private const int MAX_TOP_LEVEL_BOXES = 512;

    private const int MAX_CHILD_BOXES = 4096;

    /**
     * @return array{width: int, height: int, durationMs: int}|null
     */
    public static function probe(string $path): ?array
    {
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false || $size === false || $size < 16) {
            return null;
        }

        try {
            $moov = self::findMoov($handle, (int) $size);

            return $moov === null ? null : self::parseMoov($moov);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function findMoov($handle, int $fileSize): ?string
    {
        $offset = 0;

        for ($i = 0; $i < self::MAX_TOP_LEVEL_BOXES && $offset + 8 <= $fileSize; $i++) {
            if (fseek($handle, $offset) !== 0) {
                return null;
            }

            $header = fread($handle, 16);

            if ($header === false || strlen($header) < 8) {
                return null;
            }

            $length = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;

            if ($length === 1) {
                if (strlen($header) < 16) {
                    return null;
                }

                $parts = unpack('N2', substr($header, 8, 8));
                $length = ($parts[1] << 32) | $parts[2];
                $headerSize = 16;
            } elseif ($length === 0) {
                $length = $fileSize - $offset;
            }

            if ($length < $headerSize || $offset + $length > $fileSize) {
                return null;
            }

            if ($type === 'moov') {
                $payload = $length - $headerSize;

                if ($payload < 8 || $payload > self::MAX_MOOV_BYTES || fseek($handle, $offset + $headerSize) !== 0) {
                    return null;
                }

                $data = fread($handle, $payload);

                return $data === false || strlen($data) !== $payload ? null : $data;
            }

            $offset += $length;
        }

        return null;
    }

    /** @return array{width: int, height: int, durationMs: int}|null */
    private static function parseMoov(string $moov): ?array
    {
        $durationMs = null;
        $dimensions = null;

        foreach (self::children($moov) as [$type, $body]) {
            if ($type === 'mvhd' && $durationMs === null) {
                $durationMs = self::mvhdDuration($body);
            }

            if ($type === 'trak' && $dimensions === null) {
                $dimensions = self::videoTrack($body);
            }
        }

        if ($durationMs === null || $dimensions === null) {
            return null;
        }

        return ['width' => $dimensions[0], 'height' => $dimensions[1], 'durationMs' => $durationMs];
    }

    /**
     * Direct children of a container payload as [type, body].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function children(string $data): array
    {
        $out = [];
        $offset = 0;
        $total = strlen($data);

        for ($i = 0; $i < self::MAX_CHILD_BOXES && $offset + 8 <= $total; $i++) {
            $length = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $headerSize = 8;

            if ($length === 1) {
                if ($offset + 16 > $total) {
                    break;
                }

                $parts = unpack('N2', substr($data, $offset + 8, 8));
                $length = ($parts[1] << 32) | $parts[2];
                $headerSize = 16;
            } elseif ($length === 0) {
                $length = $total - $offset;
            }

            if ($length < $headerSize || $offset + $length > $total) {
                break;
            }

            $out[] = [$type, substr($data, $offset + $headerSize, $length - $headerSize)];
            $offset += $length;
        }

        return $out;
    }

    private static function mvhdDuration(string $body): ?int
    {
        if (strlen($body) < 20) {
            return null;
        }

        $version = ord($body[0]);

        if ($version === 1) {
            if (strlen($body) < 32) {
                return null;
            }

            $timescale = unpack('N', substr($body, 20, 4))[1];
            $parts = unpack('N2', substr($body, 24, 8));
            $duration = ($parts[1] << 32) | $parts[2];
        } else {
            $timescale = unpack('N', substr($body, 12, 4))[1];
            $duration = unpack('N', substr($body, 16, 4))[1];

            if ($duration === 0xFFFFFFFF) {
                return null;
            }
        }

        if ($timescale < 1 || $duration < 0 || $duration > PHP_INT_MAX / 1000) {
            return null;
        }

        return (int) round($duration * 1000 / $timescale);
    }

    /** @return array{0: int, 1: int}|null width, height of the first video track */
    private static function videoTrack(string $trak): ?array
    {
        $tkhd = null;
        $isVideo = false;

        foreach (self::children($trak) as [$type, $body]) {
            if ($type === 'tkhd') {
                $tkhd = $body;
            }

            if ($type === 'mdia') {
                foreach (self::children($body) as [$inner, $innerBody]) {
                    if ($inner === 'hdlr' && strlen($innerBody) >= 12 && substr($innerBody, 8, 4) === 'vide') {
                        $isVideo = true;
                    }
                }
            }
        }

        if (! $isVideo || $tkhd === null) {
            return null;
        }

        $version = $tkhd === '' ? 0 : ord($tkhd[0]);
        $matrixAt = $version === 1 ? 52 : 40;

        if (strlen($tkhd) < $matrixAt + 36 + 8) {
            return null;
        }

        $matrix = array_values(unpack('N9', substr($tkhd, $matrixAt, 36)) ?: []);
        $size = unpack('N2', substr($tkhd, $matrixAt + 36, 8));
        $width = $size[1] >> 16;
        $height = $size[2] >> 16;

        if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000) {
            return null;
        }

        // A 90 / 270 degree rotation matrix (a = d = 0) means the picture is shown turned.
        if ($matrix[0] === 0 && $matrix[4] === 0 && $matrix[1] !== 0 && $matrix[3] !== 0) {
            return [$height, $width];
        }

        return [$width, $height];
    }
}
