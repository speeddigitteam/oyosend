<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Ai\Providers\FakeAiProvider;
use Tests\TestCase;

/**
 * How other modules test against the TextAssistant contract (ADR 0024, docs/AI.md section 10).
 *
 * - `fakeTextAssistant()` replaces the contract with a scripted double: use it in a consumer's tests when the
 *   AI module is irrelevant. Queue answers with `->push(FakeTextAssistant::hashtags([...]))`, read `->tasks`.
 * - `realTextAssistant($tenant)` entitles the workspace to `ai`, switches to the scripted PROVIDER and returns
 *   it: the real gateway-backed assistant runs (limits, metering, redaction) against `->pushText('...')`.
 *   Resolve it with `app(TextAssistant::class)` inside `inTenant()`.
 *
 * @mixin TestCase
 */
trait InteractsWithTextAssistant
{
    protected function fakeTextAssistant(): FakeTextAssistant
    {
        $fake = new FakeTextAssistant;
        $this->app->instance(TextAssistant::class, $fake);

        return $fake;
    }

    protected function realTextAssistant(Tenant $tenant): FakeAiProvider
    {
        $this->entitle($tenant, 'ai');
        config(['ai.default' => 'fake']);

        /** @var FakeAiProvider $provider */
        $provider = $this->app->make(FakeAiProvider::class);

        return $provider;
    }
}
