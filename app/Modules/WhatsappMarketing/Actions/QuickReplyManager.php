<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Validation\ValidationException;

/** Saved replies. A body may contain only the allow-listed `{{contact.*}}`, `{{company.name}}`, `{{workspace.name}}`, `{{agent.name}}`. */
final class QuickReplyManager
{
    public function __construct(private readonly VariableResolver $variables) {}

    /** @param array{shortcut: string, title: string, body: string, is_active?: bool} $input */
    public function save(?QuickReply $reply, array $input, ?string $actorMembershipId = null): QuickReply
    {
        $shortcut = strtolower(trim($input['shortcut']));
        $body = Text::clean($input['body'], 1000);

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $shortcut) !== 1) {
            throw ValidationException::withMessages(['shortcut' => __('A shortcut uses lower-case letters, digits, dashes and underscores.')]);
        }

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('The reply text is required.')]);
        }

        $unknown = $this->variables->unknownPlaceholders($body);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => __('Unknown placeholder: :name', ['name' => '{{'.$unknown[0].'}}'])]);
        }

        $taken = QuickReply::query()->where('shortcut', $shortcut)->when($reply !== null, fn ($q) => $q->whereKeyNot($reply?->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['shortcut' => __('This shortcut is already used.')]);
        }

        $reply ??= new QuickReply(['created_by' => $actorMembershipId]);
        $reply->fill([
            'shortcut' => $shortcut,
            'title' => Text::clean($input['title'], 120, false),
            'body' => $body,
            'is_active' => (bool) ($input['is_active'] ?? true),
        ])->save();

        return $reply;
    }
}
