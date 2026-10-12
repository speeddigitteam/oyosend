<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Upload one or more files. Type, size and content are verified again by the media library.
 *
 * The size limit is the one the page shows (`social.media`): images and videos have their own, chosen by the
 * file's sniffed type.
 */
final class MediaUploadRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $images = (int) config('social.media.image_max_kb', 8192);
        $videos = (int) config('social.media.video_max_kb', 51200);

        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            // The overall ceiling is the larger of the two; the closure applies the one for the file's own type.
            'files.*' => ['file', 'max:'.max($images, $videos), $this->withinLimit($images, $videos)],
        ];
    }

    private function withinLimit(int $imageKb, int $videoKb): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($imageKb, $videoKb): void {
            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                return;
            }

            $isVideo = in_array((string) $value->getMimeType(), (array) config('social.media.video_mimes', []), true);
            $limit = $isVideo ? $videoKb : $imageKb;

            if ($value->getSize() > $limit * 1024) {
                $fail(__('The file must be smaller than :size MB.', ['size' => round($limit / 1024, 1)]));
            }
        };
    }
}
