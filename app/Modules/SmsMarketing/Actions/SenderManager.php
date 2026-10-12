<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Modules\SmsMarketing\Enums\RegistrationStatus;
use App\Modules\SmsMarketing\Enums\SenderIdType;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Support\SenderIdRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The workspace's recorded sender ids. Registration is the workspace's own statement and is never verified.
 */
final class SenderManager
{
    /**
     * @param  array{label: string, value: string, type: string, registration_status?: string|null, registration_reference?: string|null, registration_note?: string|null}  $attributes
     */
    public function create(array $attributes): SenderId
    {
        return $this->save(new SenderId, $attributes);
    }

    /**
     * @param  array{label: string, value: string, type: string, registration_status?: string|null, registration_reference?: string|null, registration_note?: string|null}  $attributes
     */
    public function update(SenderId $sender, array $attributes): SenderId
    {
        return $this->save($sender, $attributes);
    }

    public function delete(SenderId $sender): void
    {
        DB::transaction(static fn () => $sender->delete());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(SenderId $sender, array $attributes): SenderId
    {
        $type = SenderIdType::from((string) $attributes['type']);
        $value = trim((string) $attributes['value']);

        if (! SenderIdRules::isValid($type, $value)) {
            throw ValidationException::withMessages(['value' => $type === SenderIdType::Masking
                ? __('A masking sender id is 3 to 11 letters and digits, with at least one letter.')
                : __('A non-masking sender id is 3 to 15 digits, with an optional leading +.')]);
        }

        $status = RegistrationStatus::tryFrom((string) ($attributes['registration_status'] ?? '')) ?? RegistrationStatus::NotRegistered;

        try {
            return DB::transaction(function () use ($sender, $attributes, $type, $value, $status): SenderId {
                $sender->fill([
                    'label' => trim((string) $attributes['label']),
                    'value' => $value,
                    'type' => $type,
                    'registration_status' => $status,
                    'registration_reference' => $this->nullable($attributes['registration_reference'] ?? null),
                    'registration_note' => $this->nullable($attributes['registration_note'] ?? null),
                ])->save();

                return $sender;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['value' => __('This sender id is already recorded.')]);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
