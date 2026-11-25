<?php

declare(strict_types=1);

use App\Core\Ai\Contracts\VoiceInput;
use App\Core\Billing\Models\UsageRecord;
use App\Core\Identity\Models\User;
use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Actions\AiProviderAccounts;
use App\Modules\Ai\Actions\AiSettings;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Models\AiProviderAccount;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Providers\FakeTranscriber;
use App\Modules\Ai\Providers\TranscriberManager;
use App\Modules\Ai\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithAi;
use Tests\Support\InteractsWithBilling;

uses(InteractsWithAi::class, InteractsWithBilling::class);

/** A short WAV clip: content sniffing must see audio, whatever the file is called. */
function voiceClip(int $samples = 1600, string $name = 'speech.wav'): UploadedFile
{
    $data = str_repeat("\0", $samples * 2);
    $wav = 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
        .'fmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
        .'data'.pack('V', strlen($data)).$data;

    return UploadedFile::fake()->createWithContent($name, $wav);
}

function transcriptionAccount(array $attributes = []): AiProviderAccount
{
    return AiProviderAccount::query()->create([
        'driver' => AiDriver::OpenAiCompatible,
        'name' => 'Whisper '.bin2hex(random_bytes(3)),
        'model' => '',
        'transcription_model' => 'whisper-1',
        'base_url' => 'https://speech.example.test/v1',
        'is_enabled' => true,
        'is_default' => false,
        'is_transcription_default' => false,
        'secrets' => ['api_key' => 'sk-speech-secret'],
        ...$attributes,
    ]);
}

beforeEach(function () {
    app('cache.central')->flush();
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.api_key' => null,
        'ai.transcription.driver' => 'openai_compatible',
        'ai.transcription.base_url' => null,
        'ai.transcription.model' => null,
        'ai.transcription.api_key' => null,
    ]);
    $this->tenant = $this->aiTenant('ai-voice');
    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['voice_enabled' => true]));
});

function useFakeTranscriber(string $heard = 'Open contacts'): FakeTranscriber
{
    config(['ai.transcription.driver' => 'fake', 'ai.transcription.fake_text' => $heard]);

    return app(FakeTranscriber::class);
}

describe('transcribing a clip', function () {
    it('sends the clip to the configured server and returns only the text, metered once per clip', function () {
        config([
            'ai.transcription.base_url' => 'https://speech.example.test/v1',
            'ai.transcription.model' => 'whisper-1',
            'ai.transcription.api_key' => 'sk-env-speech',
        ]);
        Http::fake(['speech.example.test/*' => Http::response(['text' => "  কন্টাক্ট\nখোলো  "])]);
        Log::spy();

        $this->actingAsOwner($this->tenant)
            ->post($this->aiUrl($this->tenant, '/voice/transcriptions'), ['audio' => voiceClip(), 'language' => 'bn'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertExactJson(['text' => 'কন্টাক্ট খোলো']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://speech.example.test/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer sk-env-speech')
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'model' && $part['contents'] === 'whisper-1')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'bn')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'speech.wav'));

        $usage = $this->inTenant($this->tenant, fn () => UsageRecord::query()->where('meter', AiGateway::VOICE_CLIPS)->get());
        expect($usage)->toHaveCount(1)
            ->and($usage[0]->quantity)->toBe(1)
            ->and(json_encode($usage[0]->meta))->not->toContain('কন্টাক্ট');

        // Nothing heard is kept: no conversation message, and no log line carries the text.
        expect($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(0);
        Log::shouldNotHaveReceived('info');
    });

    it('defaults the language to the interface language', function () {
        $fake = useFakeTranscriber();

        $this->actingAsOwner($this->tenant)
            ->post($this->aiUrl($this->tenant, '/voice/transcriptions'), ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('text', 'Open contacts');

        expect($fake->clips)->toHaveCount(1)
            ->and($fake->clips[0]['language'])->toBe(app()->getLocale());
    });

    it('needs the voice permission, the AI app and the workspace switch', function () {
        $fake = useFakeTranscriber();
        [$withoutVoice] = $this->aiMember($this->tenant, ['ai.assistant.use']);
        [$withVoice] = $this->aiMember($this->tenant, ['ai.voice.use']);
        $url = $this->aiUrl($this->tenant, '/voice/transcriptions');

        $this->actingAsMember($this->tenant, $withoutVoice)
            ->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsMember($this->tenant, $withVoice)
            ->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])->assertOk();

        $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['voice_enabled' => false]));
        $this->actingAsMember($this->tenant, $withVoice)
            ->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('state', AiState::Disabled->value);

        $other = $this->createTenant('no-ai-voice');
        $this->actingAsOwner($other)
            ->post($this->aiUrl($other, '/voice/transcriptions'), ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertNotFound();

        expect($fake->clips)->toHaveCount(1);
    });

    it('accepts audio only, up to the size limit, and calls nothing otherwise', function () {
        $fake = useFakeTranscriber();
        config(['ai.transcription.max_kilobytes' => 4]);
        $owner = $this->actingAsOwner($this->tenant);
        $url = $this->aiUrl($this->tenant, '/voice/transcriptions');

        // A text file named and declared as audio is still text: the type is sniffed from the content. (A fake
        // upload reports the type of its name, so this one is a real file.)
        $path = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($path, 'not audio at all, just a line of text');
        $owner->post($url, ['audio' => new UploadedFile($path, 'speech.webm', 'audio/webm', null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        $owner->post($url, ['audio' => voiceClip(8000)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        $owner->post($url, [], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        $owner->post($url, ['audio' => voiceClip(), 'language' => 'fr'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('language');

        expect($fake->clips)->toBe([]);
    });

    it('says not configured without a transcription server, and calls nothing', function () {
        Http::fake();

        $this->actingAsOwner($this->tenant)
            ->post($this->aiUrl($this->tenant, '/voice/transcriptions'), ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('state', AiState::NotConfigured->value);

        Http::assertNothingSent();
    });

    it('stops at the plan voice limit', function () {
        $fake = useFakeTranscriber();
        $plan = $this->createPlan(['limits' => ['ai.voice_clips' => 1]]);
        $this->subscribeAndPay($this->tenant, $plan);
        $owner = $this->actingAsOwner($this->tenant);
        $url = $this->aiUrl($this->tenant, '/voice/transcriptions');

        $owner->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])->assertOk();
        $owner->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('state', AiState::LimitReached->value);

        expect($fake->clips)->toHaveCount(1);
    });

    it('opens only the transcription breaker when the speech server fails', function () {
        config([
            'ai.transcription.base_url' => 'https://speech.example.test/v1',
            'ai.transcription.model' => 'whisper-1',
            'ai.circuit_breaker.failures' => 2,
        ]);
        Http::fake(['speech.example.test/*' => Http::response(['error' => ['message' => 'secret provider text']], 500)]);
        $owner = $this->actingAsOwner($this->tenant);
        $url = $this->aiUrl($this->tenant, '/voice/transcriptions');

        $response = $owner->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('state', AiState::Unavailable->value);
        expect($response->getContent())->not->toContain('secret provider text');

        $owner->post($url, ['audio' => voiceClip()], ['Accept' => 'application/json'])->assertStatus(503);

        expect(CircuitBreaker::transcription()->isOpen())->toBeTrue()
            ->and(app(CircuitBreaker::class)->isOpen())->toBeFalse()
            ->and($this->inTenant($this->tenant, fn () => app(AiGateway::class)->transcriptionState()))->toBe(AiState::Unavailable);
    });
});

describe('who transcribes', function () {
    it('uses the enabled transcription account and never falls back to the environment while accounts exist', function () {
        config(['ai.transcription.base_url' => 'https://env.example.test/v1', 'ai.transcription.model' => 'env-model']);

        expect(app(TranscriberManager::class)->transcriber()->model())->toBe('env-model');

        $account = transcriptionAccount(['is_transcription_default' => true]);
        expect(app(TranscriberManager::class)->transcriber()->model())->toBe('whisper-1');

        $account->forceFill(['is_enabled' => false])->save();
        expect(app(TranscriberManager::class)->transcriber()->isConfigured())->toBeFalse();
    });

    it('makes the first account with a transcription model the voice provider, apart from the chat default', function () {
        $accounts = app(AiProviderAccounts::class);

        $chat = $accounts->create([
            'driver' => 'openai_compatible', 'name' => 'Chat', 'model' => 'gpt-test',
            'base_url' => 'https://llm.example.test/v1', 'api_key' => 'sk-1',
        ]);
        $speech = $accounts->create([
            'driver' => 'openai_compatible', 'name' => 'Speech', 'model' => '', 'transcription_model' => 'whisper-1',
            'base_url' => 'https://speech.example.test/v1',
        ]);

        expect($chat->is_default)->toBeTrue()->and($chat->is_transcription_default)->toBeFalse()
            ->and($speech->is_default)->toBeFalse()->and($speech->is_transcription_default)->toBeTrue();

        // An account without a chat model never becomes the chat default.
        expect(fn () => $accounts->makeDefault($speech))->toThrow(InvalidArgumentException::class);

        // Anthropic accounts cannot transcribe: a transcription model given to one is dropped.
        $claude = $accounts->create([
            'driver' => 'anthropic', 'name' => 'Claude', 'model' => 'claude-x', 'transcription_model' => 'whisper-1', 'api_key' => 'sk-ant',
        ]);
        expect($claude->transcription_model)->toBeNull();
    });

    it('lets the operator choose the voice provider in the panel', function () {
        $admin = $this->createPlatformUser();
        $first = transcriptionAccount(['name' => 'First', 'is_transcription_default' => true]);
        $second = transcriptionAccount(['name' => 'Second']);
        $disabled = transcriptionAccount(['name' => 'Off', 'is_enabled' => false]);

        $this->actingAsPlatformUser($admin)
            ->post("http://admin.localhost/ai/providers/{$second->id}/transcription-default")
            ->assertRedirect();

        expect($first->refresh()->is_transcription_default)->toBeFalse()
            ->and($second->refresh()->is_transcription_default)->toBeTrue();

        $this->post("http://admin.localhost/ai/providers/{$disabled->id}/transcription-default")
            ->assertSessionHasErrors('account');
        expect($disabled->refresh()->is_transcription_default)->toBeFalse();

        $this->get('http://admin.localhost/ai/providers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('transcriptionUsesEnvironment', false)
                ->where('accounts.1.transcriptionModel', 'whisper-1')
                ->missing('accounts.0.secrets'));
    });

    it('validates a transcription-only account in the panel', function () {
        $admin = $this->createPlatformUser();

        $this->actingAsPlatformUser($admin)
            ->post('http://admin.localhost/ai/providers', [
                'driver' => 'openai_compatible', 'name' => 'Whisper box', 'model' => '',
                'transcription_model' => 'whisper-large-v3', 'base_url' => 'https://whisper.example.test/v1',
            ])
            ->assertSessionHasNoErrors();

        $this->post('http://admin.localhost/ai/providers', [
            'driver' => 'openai_compatible', 'name' => 'Nothing', 'model' => '', 'base_url' => 'https://x.example.test/v1',
        ])->assertSessionHasErrors('model');

        $this->post('http://admin.localhost/ai/providers', [
            'driver' => 'anthropic', 'name' => 'Claude only speech', 'model' => '', 'transcription_model' => 'whisper-1', 'api_key' => 'k',
        ])->assertSessionHasErrors(['model', 'transcription_model']);

        expect(AiProviderAccount::query()->where('name', 'Whisper box')->firstOrFail()->is_transcription_default)->toBeTrue();
    });
});

describe('offering the microphone', function () {
    it('shares the offer only when the app, the permission, the switch and a provider are all there', function () {
        useFakeTranscriber();
        [$withVoice] = $this->aiMember($this->tenant, ['ai.voice.use']);
        [$withoutVoice] = $this->aiMember($this->tenant, ['ai.assistant.use']);
        $dashboard = $this->tenantUrl($this->tenant, '/');

        $this->actingAsMember($this->tenant, $withVoice)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page
                ->where('voice.maxSeconds', 30)
                ->where('voice.maxBytes', 2048 * 1024)
                ->where('voice.autoRun', false));

        $this->actingAsMember($this->tenant, $withoutVoice)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page->where('voice', null));

        $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['voice_enabled' => false]));
        $this->actingAsMember($this->tenant, $withVoice)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page->where('voice', null));

        $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['voice_enabled' => true]));
        config(['ai.transcription.driver' => 'openai_compatible']);
        $this->actingAsMember($this->tenant, $withVoice)->get($dashboard)
            ->assertInertia(fn (Assert $page) => $page->where('voice', null));

        $other = $this->createTenant('voice-no-app');
        $this->actingAsOwner($other)->get($this->tenantUrl($other, '/'))
            ->assertInertia(fn (Assert $page) => $page->where('voice', null));
    });

    it('hides the microphone instead of failing the page when the offer cannot be worked out', function () {
        app()->bind(VoiceInput::class, fn () => new class implements VoiceInput
        {
            public function offerFor(User $user): ?array
            {
                throw new RuntimeException('voice settings unreadable');
            }
        });

        $this->actingAsOwner($this->tenant)->get($this->tenantUrl($this->tenant, '/'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('voice', null));
    });

    it('carries the member preference, saved on the profile and kept when the field is absent', function () {
        useFakeTranscriber();
        $owner = $this->ownerOf($this->tenant);
        $profile = $this->tenantUrl($this->tenant, '/settings/profile');

        $this->actingAsMember($this->tenant, $owner)
            ->put($profile, ['name' => $owner->name, 'locale' => 'en', 'numerals' => 'latn', 'voice_auto_run' => true])
            ->assertSessionHasNoErrors();
        expect($owner->refresh()->preference('voice_auto_run'))->toBeTrue();

        $this->put($profile, ['name' => $owner->name, 'locale' => 'en', 'numerals' => 'beng'])->assertSessionHasNoErrors();
        expect($owner->refresh()->preference('voice_auto_run'))->toBeTrue()
            ->and($owner->preference('numerals'))->toBe('beng');

        $this->get($profile)->assertInertia(fn (Assert $page) => $page
            ->where('profile.voiceAutoRun', true)
            ->where('voice.autoRun', true));

        $this->put($profile, ['name' => $owner->name, 'locale' => 'en', 'numerals' => 'latn', 'voice_auto_run' => 'maybe'])
            ->assertSessionHasErrors('voice_auto_run');
    });
});

describe('workspace settings', function () {
    it('switches voice on and off and reports the speech provider', function () {
        useFakeTranscriber();
        $url = $this->aiUrl($this->tenant, '/settings');
        $payload = [
            'enabled' => true, 'chat_enabled' => true, 'drafting_enabled' => true, 'automation_enabled' => false,
            'data_scopes' => ['contacts'], 'retention_days' => 90, 'redact_pii' => false,
        ];

        $this->actingAsOwner($this->tenant)->put($url, [...$payload, 'voice_enabled' => false])->assertSessionHasNoErrors();
        expect($this->inTenant($this->tenant, fn () => app(AiSettings::class)->current()->voice_enabled))->toBeFalse();

        $this->put($url, [...$payload, 'voice_enabled' => true])->assertSessionHasNoErrors();
        // Leaving the field out keeps it.
        $this->put($url, $payload)->assertSessionHasNoErrors();

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('settings.voiceEnabled', true)
            ->where('availability.transcription.configured', true)
            ->where('availability.features.voice.state', AiState::Ready->value));
    });

    it('is off for a workspace that never chose it', function () {
        $fresh = $this->aiTenant('ai-voice-fresh');

        expect($this->inTenant($fresh, fn () => app(AiSettings::class)->current()->voice_enabled))->toBeFalse();
    });
});
