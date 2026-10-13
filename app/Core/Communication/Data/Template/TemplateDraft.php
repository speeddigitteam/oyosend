<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

use InvalidArgumentException;

/**
 * A message template to submit for approval. Validated on construction (Meta's name, category and component
 * rules); the provider may still refuse it, and says why in the result.
 */
final readonly class TemplateDraft
{
    /**
     * @param  string  $name  Lower-case letters, digits and underscores, up to 512 characters, unique per language.
     * @param  string  $language  `en`, `bn`, `en_US`, `pt_BR`, ...
     * @param  list<TemplateComponent>  $components  Exactly one body; at most one header, footer and buttons, in
     *                                               any order (the provider order is header, body, footer, buttons).
     * @param  bool  $allowCategoryChange  Let the provider re-categorize the template instead of rejecting it.
     */
    public function __construct(
        public string $name,
        public string $language,
        public TemplateCategory $category,
        public array $components,
        public bool $allowCategoryChange = false,
    ) {
        if (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            throw new InvalidArgumentException('A template name is lower-case letters, digits and underscores.');
        }

        if (preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $language) !== 1) {
            throw new InvalidArgumentException('A template language is a code such as en or en_US.');
        }

        if ($category === TemplateCategory::Authentication) {
            throw new InvalidArgumentException('Authentication templates are not supported yet.');
        }

        $counts = [];

        foreach ($components as $component) {
            $counts[$component->type] = ($counts[$component->type] ?? 0) + 1;
        }

        if (($counts[TemplateComponent::BODY] ?? 0) !== 1) {
            throw new InvalidArgumentException('A template has exactly one body.');
        }

        foreach ([TemplateComponent::HEADER, TemplateComponent::FOOTER, TemplateComponent::BUTTONS] as $type) {
            if (($counts[$type] ?? 0) > 1) {
                throw new InvalidArgumentException('A template has at most one '.$type.'.');
            }
        }

        if (count($components) !== array_sum($counts) || array_diff(array_keys($counts), [TemplateComponent::HEADER, TemplateComponent::BODY, TemplateComponent::FOOTER, TemplateComponent::BUTTONS]) !== []) {
            throw new InvalidArgumentException('A template has a header, a body, a footer and buttons only.');
        }
    }

    /**
     * Components in the provider's order: header, body, footer, buttons.
     *
     * @return list<TemplateComponent>
     */
    public function ordered(): array
    {
        $order = [TemplateComponent::HEADER => 0, TemplateComponent::BODY => 1, TemplateComponent::FOOTER => 2, TemplateComponent::BUTTONS => 3];
        $components = $this->components;
        usort($components, static fn (TemplateComponent $a, TemplateComponent $b): int => $order[$a->type] <=> $order[$b->type]);

        return $components;
    }
}
