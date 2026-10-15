<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Tenancy\Support\TenantHost;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Normalizer\SlugNormalizer;

/**
 * Turns an article's Markdown into the HTML the website shows (ADR 0037).
 *
 * Safe by construction rather than by cleaning afterwards: raw HTML in the Markdown is escaped and shown as text,
 * `javascript:` and other unsafe links are dropped, and an image is kept only when it is one the blog itself serves
 * (/blog/media/{file}); any other image becomes its description. The page has one H1, the article's title, so a
 * `#` heading in the body becomes a section heading. Every section heading gets an anchor for the table of contents.
 */
final class BlogMarkdown
{
    /** Words a reader gets through in a minute, by language: Bengali script reads a little slower. */
    private const array WORDS_PER_MINUTE = ['en' => 220, 'bn' => 160];

    public function render(string $markdown, string $locale = 'en'): RenderedArticle
    {
        $toc = [];

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use (&$toc): void {
            $toc = $this->shape($event->getDocument());
        });

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return new RenderedArticle(
            html: $html,
            toc: $toc,
            words: $words,
            minutes: self::minutes($markdown, $locale),
        );
    }

    /**
     * How long the article takes to read, from its Markdown: the same figure on a card and on the article.
     */
    public static function minutes(string $markdown, string $locale = 'en'): int
    {
        // Link targets and image addresses are not read; the marks around words are not words.
        $text = (string) preg_replace(['/\]\([^)]*\)/u', '/[#*_>`~|\[\]!-]+/u'], [' ', ' '], $markdown);
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return max(1, (int) ceil($words / (self::WORDS_PER_MINUTE[$locale] ?? 200)));
    }

    /**
     * Headings, images and links brought into the page's shape. Collected first and changed after, so the walk
     * never sees a tree it is changing.
     *
     * @return list<array{id: string, text: string}> the section headings, for the table of contents
     */
    private function shape(Document $document): array
    {
        $headings = [];
        $images = [];
        $links = [];

        foreach ($document->iterator() as $node) {
            match (true) {
                $node instanceof Heading => $headings[] = $node,
                $node instanceof Image => $images[] = $node,
                $node instanceof Link => $links[] = $node,
                default => null,
            };
        }

        $toc = [];
        $used = [];
        $slugs = new SlugNormalizer;

        foreach ($headings as $heading) {
            if ($heading->getLevel() === 1) {
                $heading->setLevel(2);
            }

            if ($heading->getLevel() > 3) {
                continue;
            }

            $text = trim(StringContainerHelper::getChildText($heading));
            $base = $slugs->normalize($text, ['length' => 60]);
            $base = $base === '' ? 'section' : $base;
            $id = $base;

            for ($n = 2; isset($used[$id]); $n++) {
                $id = "{$base}-{$n}";
            }

            $used[$id] = true;
            $heading->data->set('attributes/id', $id);

            if ($heading->getLevel() === 2 && $text !== '') {
                $toc[] = ['id' => $id, 'text' => $text];
            }
        }

        foreach ($images as $image) {
            $file = BlogMedia::fileFromUrl($image->getUrl());

            if ($file === null) {
                $image->replaceWith(new Text(StringContainerHelper::getChildText($image)));

                continue;
            }

            $image->setUrl(BlogMedia::url($file));
            $image->data->set('attributes/loading', 'lazy');
            $image->data->set('attributes/decoding', 'async');
        }

        $home = app(TenantHost::class)->domain();

        foreach ($links as $link) {
            $host = parse_url($link->getUrl(), PHP_URL_HOST);

            // Another website: it gets no handle on this page.
            if (is_string($host) && $host !== $home) {
                $link->data->set('attributes/rel', 'noopener');
            }
        }

        return $toc;
    }
}
