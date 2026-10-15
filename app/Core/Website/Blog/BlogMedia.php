<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Tenancy\Support\TenantHost;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The blog's images: covers and pictures in an article (ADR 0037). Platform files, kept on `site.blog.disk` under
 * platform/blog/, named after their content and served by the central site at /blog/media/{file}.
 *
 * Every upload is drawn again before it is kept: what is stored is pixels only, so a photo's camera details and
 * location never reach the website, a file that is not really an image is refused, a phone photo stands the right
 * way up, and a very large one is brought down to a size a page can carry. Raster images only: an SVG can carry script.
 */
final class BlogMedia
{
    /** The file names this class writes: what /blog/media/{file} answers for. */
    public const string FILE_PATTERN = '[a-f0-9]{24}\.(png|jpg|webp)';

    /** Wider than any column the website shows it in, at twice the pixels. */
    public const int MAX_WIDTH = 2400;

    private const string DIRECTORY = 'platform/blog';

    /** @var array<string, string> */
    private const array TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('site.blog.disk', 'local'));
    }

    public static function path(string $file): string
    {
        return self::DIRECTORY.'/'.$file;
    }

    public static function type(string $file): string
    {
        return self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    public static function isFile(string $file): bool
    {
        return preg_match('/^'.self::FILE_PATTERN.'$/', $file) === 1;
    }

    public static function url(string $file): string
    {
        return route('central.blog.media', ['file' => $file]);
    }

    /**
     * The pixel size of a kept image, or nulls when it cannot be read.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function dimensions(string $file): array
    {
        $contents = self::isFile($file) ? self::disk()->get(self::path($file)) : null;
        $info = is_string($contents) ? @getimagesizefromstring($contents) : false;

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }

    /**
     * The file an image address names, when it is one of the blog's own: /blog/media/{file} on the website, with or
     * without the host. Anything else is null.
     */
    public static function fileFromUrl(string $url): ?string
    {
        if (preg_match('#^(?:https?://([^/?\#]+))?/blog/media/('.self::FILE_PATTERN.')$#', $url, $match) !== 1) {
            return null;
        }

        $host = $match[1] === '' ? null : (string) parse_url('http://'.$match[1], PHP_URL_HOST);

        return $host === null || $host === app(TenantHost::class)->domain() ? $match[2] : null;
    }

    /**
     * Keep an uploaded image.
     *
     * @return array{file: string, url: string, width: int, height: int}
     *
     * @throws InvalidArgumentException when the file is not an image this can read
     */
    public function store(UploadedFile $upload): array
    {
        $contents = (string) file_get_contents((string) $upload->getRealPath());
        $info = @getimagesizefromstring($contents);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Not a PNG, JPEG or WebP image.');
        }

        $extension = match ($info[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };

        [$encoded, $width, $height] = function_exists('imagecreatefromstring')
            ? $this->redraw($contents, $extension, (string) $upload->getRealPath())
            : [$contents, (int) $info[0], (int) $info[1]];

        $file = substr(hash('sha256', $encoded), 0, 24).'.'.$extension;
        self::disk()->put(self::path($file), $encoded);

        return ['file' => $file, 'url' => self::url($file), 'width' => $width, 'height' => $height];
    }

    /**
     * Draw the image again: upright, no wider than {@see MAX_WIDTH}, and nothing but its pixels.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function redraw(string $contents, string $extension, string $path): array
    {
        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The image could not be read.');
        }

        if ($extension === 'jpg') {
            $image = $this->upright($image, $path);
        }

        if (imagesx($image) > self::MAX_WIDTH) {
            $scaled = imagescale($image, self::MAX_WIDTH, -1, IMG_BICUBIC);
            $image = $scaled instanceof GdImage ? $scaled : $image;
        }

        if ($extension !== 'jpg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        match ($extension) {
            'png' => imagepng($image, null, 8),
            'webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };

        return [(string) ob_get_clean(), imagesx($image), imagesy($image)];
    }

    /** A phone photo carries its orientation as a note; turn the pixels, since the note is not kept. */
    private function upright(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned instanceof GdImage ? $turned : $image;
    }
}
