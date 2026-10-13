<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

/** A template as the provider reports it (normalized). */
final readonly class ProviderTemplate
{
    /**
     * @param  string  $providerTemplateId  The provider's id.
     * @param  string|null  $rejectedReason  Why it was rejected, when it was (untrusted provider text).
     * @param  string|null  $qualityScore  `green`, `yellow`, `red` or `unknown`, when reported.
     * @param  list<TemplateComponent>  $components
     */
    public function __construct(
        public string $providerTemplateId,
        public string $name,
        public string $language,
        public ?TemplateCategory $category,
        public ProviderTemplateStatus $status,
        public array $components = [],
        public ?string $rejectedReason = null,
        public ?string $qualityScore = null,
    ) {}
}
