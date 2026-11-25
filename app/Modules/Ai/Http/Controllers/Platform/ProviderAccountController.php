<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers\Platform;

use App\Core\Support\Toast;
use App\Modules\Ai\Actions\AiProviderAccounts;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Models\AiProviderAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin > AI providers. Routes live in routes/platform.php behind `can:platform.settings.manage`.
 * The API key is write-only: no response of this controller carries one.
 */
final class ProviderAccountController
{
    public function __construct(private readonly AiProviderAccounts $accounts) {}

    public function index(): Response
    {
        $accounts = AiProviderAccount::query()->orderBy('name')->get();

        return Inertia::render('Platform/Pages/Ai/Providers', [
            'accounts' => $accounts->map(fn (AiProviderAccount $account): array => $this->accounts->present($account))->all(),
            'drivers' => array_map(static fn (AiDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'requiresKey' => $driver->requiresKey(),
                'requiresBaseUrl' => $driver->requiresBaseUrl(),
                'supportsTranscription' => $driver->supportsTranscription(),
            ], AiDriver::cases()),
            // Without any account the environment's key (config/ai.php) is what answers.
            'usesEnvironment' => $accounts->isEmpty(),
            'environmentConfigured' => (string) config('ai.providers.anthropic.api_key') !== '',
            // Voice input (ADR 0038): the environment transcribes only while no account has a transcription model.
            'transcriptionUsesEnvironment' => $accounts->every(fn (AiProviderAccount $account): bool => $account->transcription_model === null),
            'transcriptionEnvironmentConfigured' => (string) config('ai.transcription.base_url') !== ''
                && (string) config('ai.transcription.model') !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'driver' => ['required', Rule::enum(AiDriver::class)],
            'name' => ['required', 'string', 'max:80', Rule::unique(AiProviderAccount::class, 'name')],
            'model' => ['nullable', 'required_without:transcription_model', 'string', 'max:120'],
            'transcription_model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'is_enabled' => ['sometimes', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:2048'],
        ]);

        $driver = AiDriver::from($input['driver']);
        $this->guard($driver, $input, creating: true);

        $this->accounts->create($input);
        Toast::success(__('AI provider added.'));

        return back();
    }

    public function update(Request $request, string $account): RedirectResponse
    {
        $model = AiProviderAccount::query()->findOrFail($account);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique(AiProviderAccount::class, 'name')->ignore($model->id)],
            'model' => ['nullable', 'required_without:transcription_model', 'string', 'max:120'],
            'transcription_model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'is_enabled' => ['sometimes', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:2048'],
        ]);

        $this->guard($model->driver, $input, creating: false);

        $this->accounts->update($model, $input);
        Toast::success(__('AI provider saved.'));

        return back();
    }

    public function destroy(string $account): RedirectResponse
    {
        $this->accounts->delete(AiProviderAccount::query()->findOrFail($account));
        Toast::success(__('AI provider removed.'));

        return back();
    }

    public function makeDefault(string $account): RedirectResponse
    {
        $model = AiProviderAccount::query()->findOrFail($account);

        if (! $model->is_enabled) {
            throw ValidationException::withMessages(['account' => __('Enable the provider before making it the default.')]);
        }

        if ($model->model === '') {
            throw ValidationException::withMessages(['account' => __('Give the provider a chat model before making it the default.')]);
        }

        $this->accounts->makeDefault($model);
        Toast::success(__('Default AI provider changed.'));

        return back();
    }

    public function makeTranscriptionDefault(string $account): RedirectResponse
    {
        $model = AiProviderAccount::query()->findOrFail($account);

        if (! $model->is_enabled) {
            throw ValidationException::withMessages(['account' => __('Enable the provider before using it for voice input.')]);
        }

        if ($model->transcription_model === null || ! $model->driver->supportsTranscription()) {
            throw ValidationException::withMessages(['account' => __('Give the provider a transcription model before using it for voice input.')]);
        }

        $this->accounts->makeTranscriptionDefault($model);
        Toast::success(__('Voice input now uses this provider.'));

        return back();
    }

    public function test(string $account): RedirectResponse
    {
        $result = $this->accounts->test(AiProviderAccount::query()->findOrFail($account));

        if ($result['ok']) {
            Toast::success(__('The AI provider answered.'));
        } else {
            // A code, never the provider's text.
            Toast::error(__('The AI provider test failed (:code).', ['code' => $result['reason'] ?? 'failed']));
        }

        return back();
    }

    /**
     * Rules that depend on the driver.
     *
     * @param  array<string, mixed>  $input
     */
    private function guard(AiDriver $driver, array $input, bool $creating): void
    {
        $errors = [];

        if ($driver->requiresBaseUrl() && trim((string) ($input['base_url'] ?? '')) === '') {
            $errors['base_url'] = __('The endpoint URL is required for this provider.');
        }

        if ($creating && $driver->requiresKey() && trim((string) ($input['api_key'] ?? '')) === '') {
            $errors['api_key'] = __('The API key is required for this provider.');
        }

        if (! $driver->supportsTranscription() && trim((string) ($input['transcription_model'] ?? '')) !== '') {
            $errors['transcription_model'] = __('This provider cannot transcribe speech.');
        }

        if (! $driver->supportsTranscription() && trim((string) ($input['model'] ?? '')) === '') {
            $errors['model'] = __('The model is required for this provider.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
