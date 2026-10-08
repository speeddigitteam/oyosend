<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Audit\Models\AuditLog;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes the audit trail.
 *
 * Call it from the action that performs a change, inside the same database
 * transaction, so an entry exists if and only if the change happened.
 */
final class AuditLogger
{
    /** Property keys whose values are never written to the trail. */
    private const array SENSITIVE = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'token', 'token_hash', 'secret', 'two_factor_secret', 'two_factor_recovery_codes',
        'api_key', 'access_token', 'refresh_token', 'client_secret', 'credentials',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  string  $event  Dotted, past tense: `member.invited`, `auth.login`.
     * @param  array<string, mixed>  $properties
     * @param  Authenticatable|null  $actor  Defaults to the authenticated user of the request.
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?Authenticatable $actor = null,
        ?string $subjectLabel = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = $this->request();

        return AuditLog::query()->create([
            'tenant_id' => $this->context->id(),
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_label' => $this->actorLabel($actor),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel ?? $this->subjectLabel($subject),
            'properties' => $properties === [] ? null : $this->redact($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public function redact(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (is_string($key) && in_array(Str::lower($key), self::SENSITIVE, true)) {
                $properties[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $properties[$key] = $this->redact($value);
            }
        }

        return $properties;
    }

    private function actorLabel(?Authenticatable $actor): ?string
    {
        if (! $actor instanceof Model) {
            return null;
        }

        return $actor->getAttribute('email') ?? $actor->getAttribute('name');
    }

    private function subjectLabel(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if (method_exists($subject, 'auditLabel')) {
            return Str::limit((string) $subject->auditLabel(), 250, '');
        }

        $label = $subject->getAttribute('name') ?? $subject->getAttribute('email');

        return $label === null ? null : Str::limit((string) $label, 250, '');
    }

    private function request(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
