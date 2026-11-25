<?php

declare(strict_types=1);

namespace App\Core\Website\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A blog article's words in one language. The body is Markdown, rendered on the server with raw HTML escaped
 * (App\Core\Website\Blog\BlogMarkdown).
 *
 * @property string $id
 * @property string $post_id
 * @property string $locale
 * @property string $title
 * @property string $summary
 * @property string $body
 * @property string|null $cover_alt
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property array<int, array<string, mixed>>|null $faq as stored; {@see questions()} reads it
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BlogPostTranslation extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $fillable = ['post_id', 'locale', 'title', 'summary', 'body', 'cover_alt', 'meta_title', 'meta_description', 'faq'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['faq' => 'array'];
    }

    /**
     * @return BelongsTo<BlogPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }

    /**
     * The questions and answers, only complete ones.
     *
     * @return list<array{q: string, a: string}>
     */
    public function questions(): array
    {
        $items = [];

        foreach ($this->faq ?? [] as $item) {
            $q = trim((string) ($item['q'] ?? ''));
            $a = trim((string) ($item['a'] ?? ''));

            if ($q !== '' && $a !== '') {
                $items[] = ['q' => $q, 'a' => $a];
            }
        }

        return $items;
    }
}
