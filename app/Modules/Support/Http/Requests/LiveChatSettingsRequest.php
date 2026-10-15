<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\VisitorDetails;
use App\Modules\Support\Support\ChatOrigins;
use App\Modules\Support\Support\ChatText;
use App\Modules\Support\Support\LiveChatLimits;
use Closure;
use Illuminate\Validation\Rule;

/** The website chat widget settings. Origins are validated and stored in canonical form ({@see ChatOrigins}). */
final class LiveChatSettingsRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $local = self::local();

        return [
            'enabled' => ['required', 'boolean'],
            'allowed_origins' => ['nullable', 'array', 'max:'.LiveChatLimits::MAX_ORIGINS, 'required_if_accepted:enabled'],
            'allowed_origins.*' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($local): void {
                if (! is_string($value) || ChatOrigins::normalize($value, $local) === null) {
                    $fail($local
                        ? __('Enter a website address such as https://www.example.com (http only for localhost).')
                        : __('Enter a website address such as https://www.example.com.'));
                }
            }],
            'widget_title' => ['nullable', 'string', 'max:60'],
            'greeting' => ['nullable', 'string', 'max:300'],
            'visitor_details' => ['required', Rule::enum(VisitorDetails::class)],
            'offline_note' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'allowed_origins.required_if_accepted' => __('Add at least one website to switch the chat on.'),
            'allowed_origins.max' => __('Add at most :max websites.', ['max' => LiveChatLimits::MAX_ORIGINS]),
        ];
    }

    /**
     * The validated settings, cleaned: canonical origins without duplicates, single-line title, plain-text notes.
     *
     * @return array{enabled: bool, allowed_origins: list<string>, widget_title: string|null, greeting: string|null, visitor_details: VisitorDetails, offline_note: string|null}
     */
    public function settings(): array
    {
        $input = $this->validated();
        $origins = is_array($input['allowed_origins'] ?? null) ? array_values(array_map('strval', $input['allowed_origins'])) : [];

        return [
            'enabled' => (bool) $input['enabled'],
            'allowed_origins' => ChatOrigins::normalizeAll($origins, self::local()),
            'widget_title' => self::clean($input['widget_title'] ?? null, true),
            'greeting' => self::clean($input['greeting'] ?? null, false),
            'visitor_details' => VisitorDetails::from((string) $input['visitor_details']),
            'offline_note' => self::clean($input['offline_note'] ?? null, false),
        ];
    }

    /** Plain `http` origins are for local development only. */
    public static function local(): bool
    {
        return app()->environment('local');
    }

    private static function clean(mixed $value, bool $line): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = $line ? ChatText::line($value) : ChatText::message($value);

        return $clean === null || $clean === '' ? null : $clean;
    }
}
