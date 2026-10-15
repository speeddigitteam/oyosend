<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The public website's blog (docs/WEBSITE.md, ADR 0037). Platform data on purpose: written by the company behind
        // the platform for its own website, so there is no `tenant_id`. One row per article; its words are in
        // `blog_post_translations`, one row per language it is written in.
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The address: /blog/{slug} and /bn/blog/{slug}, the same in both languages.
            $table->string('slug', 120)->unique();
            $table->string('kind', 20);
            $table->string('status', 20)->default('draft');
            // Published and this time reached: shown. A future time is a scheduled article.
            $table->timestamp('published_at')->nullable();
            $table->boolean('featured')->default(false);
            // Module keys of the apps the article is about (App\Core\Website\SiteApps).
            $table->json('apps')->nullable();
            $table->string('cover', 80)->nullable();
            $table->unsignedSmallInteger('cover_width')->nullable();
            $table->unsignedSmallInteger('cover_height')->nullable();
            $table->string('author_name', 120)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_post_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 160);
            // The "In short" answer at the top of the article, and its description where none is set.
            $table->string('summary', 400);
            // Markdown; rendered on the server with raw HTML escaped (App\Core\Website\Blog\BlogMarkdown).
            $table->mediumText('body');
            $table->string('cover_alt', 200)->nullable();
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            // Questions and answers shown under the article and given to search engines as FAQPage: [{q, a}].
            $table->json('faq')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'locale']);
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_translations');
        Schema::dropIfExists('blog_posts');
    }
};
