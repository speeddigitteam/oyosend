<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** One profile or page the person authorised, with the tokens that act as it. */
final readonly class ProfileConnection
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $handle,
        public ?string $avatarUrl,
        public string $type,
        public TokenSet $tokens,
    ) {}
}
