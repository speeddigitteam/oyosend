<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

use App\Core\Communication\Contracts\TemplateCatalogue;

/** The answer of a {@see TemplateCatalogue} call. */
final readonly class TemplateCatalogueResult
{
    /**
     * @param  string|null  $reason  A short machine code when not ok (`provider_rejected`, `provider_unreachable`,
     *                               `provider_authentication_failed`, `provider_rate_limited`, `provider_error`),
     *                               plus `detail`.
     * @param  string|null  $detail  The provider's own explanation (untrusted text, up to 300 characters).
     * @param  list<ProviderTemplate>  $templates  A page of `list()`.
     * @param  ProviderTemplate|null  $template  The template `create()` made.
     * @param  string|null  $nextCursor  Pass as `$after` for the next page; null on the last page.
     */
    public function __construct(
        public TemplateCatalogueStatus $status,
        public ?string $reason = null,
        public ?string $detail = null,
        public array $templates = [],
        public ?ProviderTemplate $template = null,
        public ?string $nextCursor = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === TemplateCatalogueStatus::Ok;
    }
}
