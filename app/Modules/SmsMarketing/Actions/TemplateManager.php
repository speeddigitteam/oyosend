<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use App\Modules\SmsMarketing\Models\Template;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Message templates with variables from the fixed allow-list. */
final class TemplateManager
{
    public function __construct(private readonly MessageComposer $composer) {}

    /**
     * @param  array{name: string, purpose: string, body: string, is_active?: bool}  $attributes
     */
    public function create(array $attributes): Template
    {
        return $this->save(new Template, $attributes);
    }

    /**
     * @param  array{name: string, purpose: string, body: string, is_active?: bool}  $attributes
     */
    public function update(Template $template, array $attributes): Template
    {
        return $this->save($template, $attributes);
    }

    public function delete(Template $template): void
    {
        DB::transaction(static fn () => $template->delete());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Template $template, array $attributes): Template
    {
        $purpose = TemplatePurpose::from((string) $attributes['purpose']);
        $body = trim((string) $attributes['body']);

        $this->composer->assertValid($body, $purpose);

        try {
            return DB::transaction(function () use ($template, $attributes, $purpose, $body): Template {
                $template->fill([
                    'name' => trim((string) $attributes['name']),
                    'purpose' => $purpose,
                    'body' => $body,
                    'is_active' => (bool) ($attributes['is_active'] ?? true),
                ])->save();

                return $template;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('A template with this name already exists.')]);
        }
    }
}
