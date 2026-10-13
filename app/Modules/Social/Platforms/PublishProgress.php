<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** What one {@see StepwisePublisher::advance()} call achieved. */
final readonly class PublishProgress
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $state,
        public ?string $remoteId,
        public int $offset = 0,
        public array $meta = [],
        public ?string $externalId = null,
        public ?PublishedPost $published = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function published(PublishedPost $post): self
    {
        return new self('published', null, published: $post, externalId: $post->externalId);
    }
}
