<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

/**
 * Builds a tiny, structurally valid MP4 / QuickTime header (ftyp, moov with mvhd and one video trak,
 * a filler mdat). Only the boxes the probe reads are real; the file is not playable.
 */
final class Mp4Builder
{
    public static function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }

    /** @param array{rotate?: bool, moovLast?: bool, brand?: string, payload?: int, version?: int, timescale?: int, audioFirst?: bool} $options */
    public static function make(float $seconds, int $width, int $height, array $options = []): string
    {
        $timescale = $options['timescale'] ?? 1000;
        $duration = (int) round($seconds * $timescale);
        $version = $options['version'] ?? 0;
        $identity = pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
        $rotated = pack('N9', 0, 0x10000, 0, 0xFFFF0000, 0, 0, 0, 0, 0x40000000);
        $matrix = ($options['rotate'] ?? false) ? $rotated : $identity;

        if ($version === 1) {
            $mvhd = "\x01\x00\x00\x00".pack('J', 0).pack('J', 0).pack('N', $timescale).pack('J', $duration).str_repeat("\0", 80);
            $tkhd = "\x01\x00\x00\x07".pack('J', 0).pack('J', 0).pack('N', 1).pack('N', 0).pack('J', $duration)
                .str_repeat("\0", 8).str_repeat("\0", 8).$matrix.pack('N', $width << 16).pack('N', $height << 16);
        } else {
            $mvhd = "\x00\x00\x00\x00".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', $duration).str_repeat("\0", 80);
            $tkhd = "\x00\x00\x00\x07".pack('N', 0).pack('N', 0).pack('N', 1).pack('N', 0).pack('N', $duration)
                .str_repeat("\0", 8).str_repeat("\0", 8).$matrix.pack('N', $width << 16).pack('N', $height << 16);
        }

        $hdlr = fn (string $handler): string => self::box('hdlr', "\0\0\0\0\0\0\0\0".$handler.str_repeat("\0", 12)."\0");
        $video = self::box('trak', self::box('tkhd', $tkhd).self::box('mdia', $hdlr('vide')));
        $audio = self::box('trak', self::box('tkhd', str_replace(pack('N', $width << 16), pack('N', 0), $tkhd)).self::box('mdia', $hdlr('soun')));
        $traks = ($options['audioFirst'] ?? false) ? $audio.$video : $video.$audio;

        $moov = self::box('moov', self::box('mvhd', $mvhd).$traks);
        $ftyp = self::box('ftyp', ($options['brand'] ?? 'isom')."\0\0\2\0".'isomiso2mp41');
        $mdat = self::box('mdat', str_repeat('x', $options['payload'] ?? 1024));

        return ($options['moovLast'] ?? false) ? $ftyp.$mdat.$moov : $ftyp.$moov.$mdat;
    }
}
