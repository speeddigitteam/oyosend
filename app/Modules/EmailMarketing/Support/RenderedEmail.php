<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

/** A finished email for one recipient: the subject line, the HTML version and the plain-text version. */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
