<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Crm;

use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Data\AudienceSegment;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\ListMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The CRM's customer groups as a WhatsApp campaign audience (ADR 0023). This app reads the groups and the
 * contact ids through the core `CrmAudience` contract only; it imports no CRM model and decides no consent.
 *
 * Every read is narrowed to what the launching member may see in the CRM (the contract applies `RecordAccess`
 * for the viewer). Materialising a segment freezes those contacts into an ordinary contact list, which then
 * feeds the existing campaign pipeline unchanged: the communication platform still decides consent and
 * suppression at snapshot time and again at send time.
 */
final class CrmSegments
{
    public function __construct(
        private readonly CrmAudience $audience,
        private readonly TenantContext $context,
    ) {}

    /**
     * The segments the member may pick, with their approximate (scope-narrowed) size.
     *
     * @return list<array{id: string, label: string, kind: string, approximateSize: int}>
     */
    public function forMember(TenantMembership $viewer): array
    {
        return array_map(static fn (AudienceSegment $segment): array => [
            'id' => $segment->id,
            'label' => $segment->label,
            'kind' => $segment->kind->value,
            'approximateSize' => $segment->approximateSize,
        ], $this->audience->segments($viewer));
    }

    public function find(string $segmentId, TenantMembership $viewer): ?AudienceSegment
    {
        foreach ($this->audience->segments($viewer) as $segment) {
            if ($segment->id === $segmentId) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Fill a list with the contacts of a segment, narrowed to the viewer's CRM record scope, up to the cap.
     *
     * @return int How many contacts were added.
     */
    public function materialize(ContactList $list, string $segmentId, TenantMembership $viewer): int
    {
        $cap = $this->cap();
        $tenantId = $this->context->requireId();
        $added = 0;
        $after = null;

        do {
            $page = $this->audience->contactIds($segmentId, min(CrmAudience::MAX_PAGE, 500), $after, $viewer);

            if ($page->ids !== []) {
                $added += $this->insert($list, $page->ids, $tenantId, $cap - $this->count($list));
            }

            $after = $page->nextCursor;
        } while ($after !== null && $this->count($list) < $cap);

        return $added;
    }

    public function cap(): int
    {
        return max(1, min(
            (int) config('whatsapp_marketing.max_recipients', 10000),
            (int) config('whatsapp_marketing.max_list_members', 10000),
        ));
    }

    /**
     * @param  list<string>  $contactIds
     */
    private function insert(ContactList $list, array $contactIds, string $tenantId, int $room): int
    {
        if ($room <= 0 || $contactIds === []) {
            return 0;
        }

        return (int) DB::transaction(function () use ($list, $contactIds, $tenantId, $room): int {
            $existing = ListMember::query()->where('list_id', $list->id)->whereIn('contact_id', $contactIds)->pluck('contact_id')->all();
            $new = array_slice(array_values(array_diff($contactIds, $existing)), 0, max(0, $room));
            $now = now();

            ListMember::query()->insertOrIgnore(array_map(static fn (string $contactId): array => [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'list_id' => $list->id,
                'contact_id' => $contactId,
                'added_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $new));

            return count($new);
        });
    }

    private function count(ContactList $list): int
    {
        return ListMember::query()->where('list_id', $list->id)->count();
    }
}
