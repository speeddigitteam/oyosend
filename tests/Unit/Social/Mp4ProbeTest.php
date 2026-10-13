<?php

declare(strict_types=1);

use App\Modules\Social\Support\Mp4Probe;
use Tests\Support\Fixtures\Mp4Builder;

function probeBytes(string $bytes): ?array
{
    $path = tempnam(sys_get_temp_dir(), 'mp4');
    file_put_contents($path, $bytes);

    try {
        return Mp4Probe::probe($path);
    } finally {
        @unlink($path);
    }
}

it('reads the length and picture size from the header without ffmpeg', function () {
    expect(probeBytes(Mp4Builder::make(42.5, 1080, 1920)))->toBe(['width' => 1080, 'height' => 1920, 'durationMs' => 42500]);
});

it('finds a moov box placed after the media data and reads a 64-bit header', function () {
    expect(probeBytes(Mp4Builder::make(10, 1280, 720, ['moovLast' => true, 'payload' => 200000])))
        ->toBe(['width' => 1280, 'height' => 720, 'durationMs' => 10000])
        ->and(probeBytes(Mp4Builder::make(90, 720, 1280, ['version' => 1, 'timescale' => 90000])))
        ->toBe(['width' => 720, 'height' => 1280, 'durationMs' => 90000]);
});

it('uses the first video track and turns the size of a rotated picture', function () {
    expect(probeBytes(Mp4Builder::make(5, 1920, 1080, ['audioFirst' => true])))->toBe(['width' => 1920, 'height' => 1080, 'durationMs' => 5000])
        ->and(probeBytes(Mp4Builder::make(5, 1920, 1080, ['rotate' => true])))->toBe(['width' => 1080, 'height' => 1920, 'durationMs' => 5000]);
});

it('answers null for anything it cannot read, never zero and never an error', function () {
    $valid = Mp4Builder::make(10, 640, 480);

    expect(probeBytes(''))->toBeNull()
        ->and(probeBytes('not a video at all, just some text bytes'))->toBeNull()
        ->and(probeBytes(substr($valid, 0, 40)))->toBeNull()
        // A box that claims to be larger than the file.
        ->and(probeBytes(pack('N', 4000000).'moov'.str_repeat("\0", 64)))->toBeNull()
        // A box of declared size smaller than its own header.
        ->and(probeBytes(pack('N', 3).'free'.str_repeat("\0", 64)))->toBeNull()
        // A track with no video handler.
        ->and(probeBytes(Mp4Builder::box('moov', Mp4Builder::box('mvhd', str_repeat("\0", 100)))))->toBeNull();
});
