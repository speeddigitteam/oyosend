<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

/**
 * An article's body as the website shows it ({@see BlogMarkdown}).
 */
final readonly class RenderedArticle
{
    /**
     * @param  string  $html  safe to print: built from escaped Markdown
     * @param  list<array{id: string, text: string}>  $toc  the section headings, in order
     */
    public function __construct(
        public string $html,
        public array $toc,
        public int $words,
        public int $minutes,
    ) {}
}
