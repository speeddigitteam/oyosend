<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** Where to send the person to authorise, and the PKCE verifier the callback needs (kept in the session). */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public ?string $codeVerifier = null,
    ) {}
}
