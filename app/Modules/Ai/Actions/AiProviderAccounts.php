<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Models\AiProviderAccount;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Providers\TranscriberManager;
use App\Modules\Ai\Support\AudioClip;
use App\Modules\Ai\Support\CircuitBreaker;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The operator's AI provider accounts (extends ADR 0013). The API key is stored encrypted, is write-only (an
 * empty value on update keeps the stored one) and appears in no return value, audit entry, log line or
 * exception message of this class. Exactly one enabled account can be the platform default.
 *
 * Independently, at most one enabled account with a transcription model transcribes voice input (ADR 0038).
 * An account may do only that: a self-hosted Whisper server has a transcription model and no chat model, and
 * an account without a chat model never becomes the chat default.
 */
final class AiProviderAccounts
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProviderManager $providers,
        private readonly CircuitBreaker $breaker,
        private readonly TranscriberManager $transcribers,
    ) {}

    /**
     * What a page may show about an account: whether a key is set, never the key.
     *
     * @return array<string, mixed>
     */
    public function present(AiProviderAccount $account): array
    {
        return [
            'id' => $account->id,
            'driver' => $account->driver->value,
            'driverLabel' => $account->driver->label(),
            'name' => $account->name,
            'model' => $account->model,
            'transcriptionModel' => $account->transcription_model,
            'baseUrl' => $account->base_url,
            'enabled' => $account->is_enabled,
            'isDefault' => $account->is_default,
            'isTranscriptionDefault' => $account->is_transcription_default,
            'hasKey' => $account->hasKey(),
            'lastTest' => $account->last_tested_at === null ? null : [
                'status' => $account->last_test_status,
                'reason' => $account->last_test_reason,
                'at' => $account->last_tested_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  driver, name, model, transcription_model, base_url, is_enabled, api_key
     */
    public function create(array $input): AiProviderAccount
    {
        $driver = AiDriver::from((string) $input['driver']);
        $key = $this->key($input);

        $account = DB::transaction(function () use ($input, $driver, $key): AiProviderAccount {
            $enabled = (bool) ($input['is_enabled'] ?? true);
            $model = trim((string) ($input['model'] ?? ''));
            $transcription = $this->transcriptionModel($driver, $input);
            // The first usable account becomes the default so adding one is enough to switch it on.
            $makeDefault = $enabled && $model !== ''
                && ! AiProviderAccount::query()->where('is_default', true)->where('is_enabled', true)->exists();
            $makeTranscriptionDefault = $enabled && $transcription !== null
                && ! AiProviderAccount::query()->where('is_transcription_default', true)->where('is_enabled', true)->exists();

            if ($makeDefault) {
                AiProviderAccount::query()->where('is_default', true)->update(['is_default' => false]);
            }

            if ($makeTranscriptionDefault) {
                AiProviderAccount::query()->where('is_transcription_default', true)->update(['is_transcription_default' => false]);
            }

            $account = AiProviderAccount::query()->create([
                'driver' => $driver,
                'name' => trim((string) $input['name']),
                'model' => $model,
                'transcription_model' => $transcription,
                'base_url' => $this->baseUrl($input),
                'is_enabled' => $enabled,
                'is_default' => $makeDefault,
                'is_transcription_default' => $makeTranscriptionDefault,
                'secrets' => $key === '' ? null : ['api_key' => $key],
            ]);

            $this->audit->record('platform.ai_provider.created', null, [
                'account' => $account->id, 'driver' => $driver->value, 'name' => $account->name,
                'model' => $account->model, 'transcription_model' => $account->transcription_model,
                'enabled' => $account->is_enabled, 'default' => $account->is_default,
                'transcription_default' => $account->is_transcription_default, 'key_set' => $key !== '',
            ], subjectLabel: $account->name);

            return $account;
        });

        $this->resetBreakers();

        return $account;
    }

    /**
     * The driver is fixed. An empty `api_key` keeps the stored key.
     *
     * @param  array<string, mixed>  $input  name, model, transcription_model, base_url, is_enabled, api_key
     */
    public function update(AiProviderAccount $account, array $input): AiProviderAccount
    {
        $key = $this->key($input);

        DB::transaction(function () use ($account, $input, $key): void {
            $account->fill([
                'name' => trim((string) $input['name']),
                'model' => trim((string) ($input['model'] ?? '')),
                'transcription_model' => $this->transcriptionModel($account->driver, $input),
                'base_url' => $this->baseUrl($input),
                'is_enabled' => (bool) ($input['is_enabled'] ?? $account->is_enabled),
            ]);

            // A disabled account cannot be a default, nor the chat default without a chat model, nor the
            // transcription default without a transcription model.
            if (! $account->is_enabled || $account->model === '') {
                $account->is_default = false;
            }

            if (! $account->is_enabled || $account->transcription_model === null) {
                $account->is_transcription_default = false;
            }

            if ($key !== '') {
                $account->secrets = ['api_key' => $key];
            }

            $account->save();

            $this->audit->record('platform.ai_provider.updated', null, [
                'account' => $account->id, 'driver' => $account->driver->value, 'name' => $account->name,
                'model' => $account->model, 'transcription_model' => $account->transcription_model,
                'enabled' => $account->is_enabled, 'default' => $account->is_default,
                'transcription_default' => $account->is_transcription_default, 'key_replaced' => $key !== '',
            ], subjectLabel: $account->name);
        });

        $this->resetBreakers();

        return $account;
    }

    public function makeDefault(AiProviderAccount $account): void
    {
        if (! $account->is_enabled) {
            throw new InvalidArgumentException('A disabled account cannot be the default.');
        }

        if ($account->model === '') {
            throw new InvalidArgumentException('An account without a chat model cannot be the default.');
        }

        DB::transaction(function () use ($account): void {
            AiProviderAccount::query()->where('is_default', true)->where('id', '!=', $account->id)->update(['is_default' => false]);
            $account->forceFill(['is_default' => true])->save();

            $this->audit->record('platform.ai_provider.default_changed', null, [
                'account' => $account->id, 'name' => $account->name,
            ], subjectLabel: $account->name);
        });

        // The failure history belonged to the previous provider.
        $this->breaker->reset();
    }

    /** Make this account the one that transcribes voice input, platform-wide. */
    public function makeTranscriptionDefault(AiProviderAccount $account): void
    {
        if (! $account->is_enabled) {
            throw new InvalidArgumentException('A disabled account cannot transcribe.');
        }

        if ($account->transcription_model === null || ! $account->driver->supportsTranscription()) {
            throw new InvalidArgumentException('The account has no transcription model.');
        }

        DB::transaction(function () use ($account): void {
            AiProviderAccount::query()->where('is_transcription_default', true)->where('id', '!=', $account->id)
                ->update(['is_transcription_default' => false]);
            $account->forceFill(['is_transcription_default' => true])->save();

            $this->audit->record('platform.ai_provider.transcription_default_changed', null, [
                'account' => $account->id, 'name' => $account->name,
            ], subjectLabel: $account->name);
        });

        CircuitBreaker::transcription()->reset();
    }

    public function delete(AiProviderAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $account->delete();

            $this->audit->record('platform.ai_provider.deleted', null, [
                'account' => $account->id, 'driver' => $account->driver->value, 'name' => $account->name, 'was_default' => $account->is_default,
                'was_transcription_default' => $account->is_transcription_default,
            ], subjectLabel: $account->name);
        });

        $this->resetBreakers();
    }

    /**
     * One minimal call (a single output token budget of 8) to prove the account works. Returns `ok` or a safe
     * reason code (`not_configured`, `unreachable`, `unauthorized`, `rate_limited`, `http_error`, `bad_response`,
     * `failed`); the provider's own text is never read out.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function test(AiProviderAccount $account): array
    {
        $reason = null;
        $provider = $this->providers->forAccount($account);
        $transcriber = $this->transcribers->forAccount($account);

        try {
            if ($account->model !== '') {
                if (! $provider->isConfigured()) {
                    $reason = 'not_configured';
                } else {
                    $provider->complete(new CompletionRequest('Reply with the single word: ok.', [['role' => 'user', 'content' => 'ping']], [], 8));
                }
            }

            // A transcription model is tested with half a second of generated silence: no recording is involved.
            if ($reason === null && $account->transcription_model !== null) {
                if (! $transcriber->isConfigured()) {
                    $reason = 'not_configured';
                } else {
                    $transcriber->transcribe(new AudioClip(self::silence(), 'audio/wav', 'wav'));
                }
            }
        } catch (ProviderNotConfigured) {
            $reason = 'not_configured';
        } catch (ProviderFailure $failure) {
            $reason = $failure->reason;
        } catch (Throwable) {
            $reason = 'failed';
        }

        $account->forceFill([
            'last_test_status' => $reason === null ? 'ok' : 'failed',
            'last_test_reason' => $reason,
            'last_tested_at' => now(),
        ])->save();

        $this->audit->record('platform.ai_provider.tested', null, [
            'account' => $account->id, 'status' => $reason === null ? 'ok' : 'failed', 'reason' => $reason,
        ], subjectLabel: $account->name);

        return ['ok' => $reason === null, 'reason' => $reason];
    }

    /**
     * The transcription model, or null when the driver cannot transcribe or none was given.
     *
     * @param  array<string, mixed>  $input
     */
    private function transcriptionModel(AiDriver $driver, array $input): ?string
    {
        $value = $input['transcription_model'] ?? null;
        $model = is_string($value) ? trim($value) : '';

        return $driver->supportsTranscription() && $model !== '' ? $model : null;
    }

    /** Half a second of 16 kHz, 16-bit mono silence as a WAV file. */
    private static function silence(): string
    {
        $samples = str_repeat("\0", 16000);

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }

    /** The provider behind either breaker may have changed: forget both failure histories. */
    private function resetBreakers(): void
    {
        $this->breaker->reset();
        CircuitBreaker::transcription()->reset();
    }

    /** @param  array<string, mixed>  $input */
    private function key(array $input): string
    {
        $key = $input['api_key'] ?? null;

        return is_string($key) ? trim($key) : '';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function baseUrl(array $input): ?string
    {
        $value = $input['base_url'] ?? null;
        $url = is_string($value) ? trim($value) : '';

        return $url === '' ? null : rtrim($url, '/');
    }
}
