<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\YouTube;

/**
 * The title and description a YouTube upload gets from a post's text.
 *
 * The post's own title is internal and never published. The video title is the first line of the text
 * that still has content once the characters YouTube refuses (`<` and `>`) are removed, cut to the title
 * limit (characters). The description is the whole text without those characters; YouTube counts its
 * limit in bytes (UTF-8), so a link is appended only when it fits.
 */
final readonly class YouTubeVideoText
{
    public function __construct(
        public string $title,
        public string $description,
        public bool $titleShortened,
        public bool $removedBrackets,
        public int $descriptionBytes,
    ) {}

    public static function from(string $text, int $titleMax, ?string $linkUrl = null, int $descriptionMaxBytes = 0): self
    {
        $removed = str_contains($text, '<') || str_contains($text, '>');
        $clean = str_replace(['<', '>'], '', $text);
        $title = '';
        $shortened = false;

        foreach (preg_split('/\R/u', $clean) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

            if ($line === '') {
                continue;
            }

            if ($titleMax > 0 && mb_strlen($line) > $titleMax) {
                $line = rtrim(mb_substr($line, 0, $titleMax));
                $shortened = true;
            }

            $title = $line;

            break;
        }

        $description = trim($clean);
        $bytes = strlen($description);
        $link = $linkUrl === null ? '' : str_replace(['<', '>'], '', $linkUrl);

        if ($link !== '' && ! str_contains($description, $link)) {
            $withLink = trim($description."\n\n".$link);

            if ($descriptionMaxBytes <= 0 || strlen($withLink) <= $descriptionMaxBytes) {
                $description = $withLink;
            }
        }

        return new self($title, $description, $shortened, $removed, $bytes);
    }

    public function hasTitle(): bool
    {
        return $this->title !== '';
    }
}
