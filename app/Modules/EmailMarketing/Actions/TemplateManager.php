<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Models\Template;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Illuminate\Validation\ValidationException;

/** Reusable email designs. A campaign copies a template's blocks; later template edits never change it. */
final class TemplateManager
{
    /** @param  array<string, mixed>  $input */
    public function create(array $input): Template
    {
        $attributes = $this->attributes($input);
        $this->assertFreeName($attributes['name']);

        return Template::query()->create($attributes);
    }

    /** @param  array<string, mixed>  $input */
    public function update(Template $template, array $input): Template
    {
        $attributes = $this->attributes($input);
        $this->assertFreeName($attributes['name'], $template->id);
        $template->fill($attributes)->save();

        return $template;
    }

    public function delete(Template $template): void
    {
        $template->delete();
    }

    private function assertFreeName(string $name, ?string $ignore = null): void
    {
        $query = Template::query()->where('name', $name);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('This name is already used.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(array $input): array
    {
        $name = ContentBlocks::line($input['name'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => __('A name of up to 120 characters is required.')]);
        }

        $preheader = ContentBlocks::line($input['preheader'] ?? '');

        if (mb_strlen($preheader) > 200) {
            throw ValidationException::withMessages(['preheader' => __('The preview text is up to 200 characters.')]);
        }

        return [
            'name' => $name,
            'subject' => ContentBlocks::subject($input['subject'] ?? '', 'subject', false),
            'preheader' => $preheader === '' ? null : $preheader,
            'blocks' => ContentBlocks::normalize($input['blocks'] ?? null),
        ];
    }
}
