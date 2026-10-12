<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

use Illuminate\Validation\ValidationException;

/** Cleans a list of hashtags typed as free text: `#One two,#three` becomes `['#One', '#two', '#three']`. */
final class HashtagNormalizer
{
    public const int MAX_TAGS = 30;

    /**
     * @param  string|list<string>  $input
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function normalize(string|array $input): array
    {
        $raw = is_array($input) ? $input : (preg_split('/[\s,;]+/u', $input) ?: []);
        $tags = [];

        foreach ($raw as $tag) {
            $tag = ltrim(trim((string) $tag), '#');

            if ($tag === '') {
                continue;
            }

            if (! preg_match('/^[\p{L}\p{M}\p{N}_]{1,100}$/u', $tag)) {
                throw ValidationException::withMessages(['tags' => __('Hashtags may contain only letters, numbers and underscores.')]);
            }

            $tags['#'.mb_strtolower($tag)] ??= '#'.$tag;
        }

        if ($tags === [] || count($tags) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('Add between 1 and :max hashtags.', ['max' => self::MAX_TAGS])]);
        }

        return array_values($tags);
    }
}
