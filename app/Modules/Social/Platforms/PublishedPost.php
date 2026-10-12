<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

final readonly class PublishedPost
{
    public function __construct(
        public string $externalId,
        public ?string $url = null,
    ) {}
}
