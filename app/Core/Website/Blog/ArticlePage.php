<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Website\Models\BlogPost;
use App\Core\Website\SiteApp;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteCompany;
use App\Core\Website\SitePages;
use App\Core\Website\SiteSchema;
use App\Core\Website\SiteUrls;
use Illuminate\Contracts\View\View;

/**
 * One article as the website shows it: the public page, and the same page as the Super Admin panel previews it
 * before it is published (then marked noindex and never cached by anyone).
 */
final readonly class ArticlePage
{
    public function __construct(
        private SitePages $pages,
        private SiteApps $apps,
        private SiteCompany $company,
        private BlogPosts $blog,
        private BlogMarkdown $markdown,
    ) {}

    public function render(BlogPost $post, string $locale, bool $preview = false): View
    {
        $words = $post->in($locale);
        abort_if($words === null, 404);

        $card = PostCard::of($post, $locale);
        $article = $this->markdown->render($words->body, $locale);
        $faq = $words->questions();
        $title = filled($words->meta_title) ? (string) $words->meta_title : $words->title;
        $description = filled($words->meta_description) ? (string) $words->meta_description : $words->summary;

        $apps = array_values(array_filter(array_map($this->apps->forModule(...), $post->appModules())));
        $mentions = array_map(static fn (SiteApp $app): array => [
            'name' => 'Oyosend '.$app->text('name'),
            'url' => SiteUrls::to('app', $locale, ['app' => $app->slug]),
        ], $apps);

        $alternates = [];

        foreach ($post->locales() as $written) {
            $alternates[$written] = SiteUrls::to('post', $written, ['post' => $post->slug]);
        }

        // Not written in the other language: the switch goes to the blog in it, or its home page.
        $other = SiteUrls::otherLocale($locale);
        $fallback = $this->blog->any($other) ? SiteUrls::to('blog', $other) : SiteUrls::to('home', $other);

        $schema = [SiteSchema::article($card, $description, $locale, $post->author_name, $article->words, $mentions)];

        if ($faq !== []) {
            $schema[] = SiteSchema::faq($card->url, $faq);
        }

        $meta = $this->pages->meta(
            'post',
            $title,
            $description,
            parameters: ['post' => $post->slug],
            trail: [[__('site.nav.blog'), SiteUrls::to('blog', $locale)], [$words->title, $card->url]],
            schema: $schema,
            alternates: $alternates,
        )->with([
            'type' => 'article',
            'index' => ! $preview,
            'fallbacks' => [$other => $fallback],
            'article' => [
                'published' => $card->published->toIso8601String(),
                'modified' => $card->updated->max($card->published)->toIso8601String(),
                'section' => $card->kindLabel,
            ],
            'picture' => $card->cover === null ? null : [
                'url' => $card->cover,
                'width' => $card->coverWidth,
                'height' => $card->coverHeight,
                'alt' => $card->coverAlt,
            ],
            'feed' => SiteUrls::to('blog_feed', $locale),
        ]);

        return $this->pages->render('site.pages.post', $meta, [
            'post' => $card,
            'article' => $article,
            'author' => filled($post->author_name) ? (string) $post->author_name : $this->company->name(),
            'faq' => $faq,
            'apps' => $apps,
            'other' => isset($alternates[$other]) ? $alternates[$other] : null,
            'related' => $preview ? [] : $this->blog->related($post, $locale),
            'share' => $this->share($card),
            'preview' => $preview,
        ]);
    }

    /**
     * Plain links that open each network's own share page: no script and nothing that follows the reader.
     *
     * @return array<string, string> network => address
     */
    private function share(PostCard $post): array
    {
        $url = rawurlencode($post->url);
        $text = rawurlencode($post->title);

        return [
            'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$url}",
            'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$url}",
            'x' => "https://x.com/intent/post?url={$url}&text={$text}",
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($post->title.' '.$post->url),
        ];
    }
}
