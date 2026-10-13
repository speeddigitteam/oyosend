<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\AudienceSegment;
use Tests\Support\Fixtures\FakeCrmAudience;
use Tests\Support\Fixtures\RecordingLeadIntake;

/**
 * Lets another app's test replace the CRM contracts (ADR 0023): `LeadIntake` and `CrmAudience`.
 * `uses(InteractsWithCrmContracts::class)` in the test file, then:
 *
 *     $intake = $this->fakeLeadIntake();            // records captures; same key => replayed
 *     $this->fakeLeadIntake(available: false);      // CRM not entitled: every capture is `unavailable`
 *     $audience = $this->fakeCrmAudience([new AudienceSegment('tag:1', 'VIP', AudienceSegmentKind::Tag, 2)], ['tag:1' => [$a, $b]]);
 *
 * For the real implementation use `registerModule(CRMServiceProvider::class)` and `entitle($tenant, 'crm')`.
 */
trait InteractsWithCrmContracts
{
    protected function fakeLeadIntake(bool $available = true): RecordingLeadIntake
    {
        $fake = new RecordingLeadIntake($available);
        $this->app->instance(LeadIntake::class, $fake);

        return $fake;
    }

    /**
     * @param  list<AudienceSegment>  $segments
     * @param  array<string, list<string>>  $contacts  segment id => shared contact ids
     */
    protected function fakeCrmAudience(array $segments = [], array $contacts = []): FakeCrmAudience
    {
        $fake = new FakeCrmAudience($segments, $contacts);
        $this->app->instance(CrmAudience::class, $fake);

        return $fake;
    }
}
