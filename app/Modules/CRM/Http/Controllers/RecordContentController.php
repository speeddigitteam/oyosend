<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\CRM\Actions\CrmContentManager;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RecordContentController
{
    public function __construct(private readonly RecordAccess $records, private readonly CrmControllerSupport $support, private readonly CrmContentManager $content, private readonly TenantStorage $storage) {}

    public function leadNote(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, LeadController::EDIT, $lead);
        $input = $request->validate(['body' => ['required', 'string', 'max:20000'], 'pinned' => ['sometimes', 'boolean']]);
        $this->content->addNote($input['body'], ['lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'company_id' => $lead->company_id], $this->support->membership($actor), (bool) ($input['pinned'] ?? false));
        Toast::success(__('Note added.'));

        return back();
    }

    public function dealNote(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, DealController::EDIT, $deal);
        $input = $request->validate(['body' => ['required', 'string', 'max:20000'], 'pinned' => ['sometimes', 'boolean']]);
        $this->content->addNote($input['body'], ['deal_id' => $deal->id, 'lead_id' => $deal->lead_id, 'contact_id' => $deal->contact_id, 'company_id' => $deal->company_id], $this->support->membership($actor), (bool) ($input['pinned'] ?? false));
        Toast::success(__('Note added.'));

        return back();
    }

    public function leadTags(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, LeadController::EDIT, $lead);
        $input = $request->validate(['tags' => ['present', 'array', 'max:100'], 'tags.*' => ['string', 'size:26']]);
        $this->content->syncTags(CrmEntityType::Lead, $lead->id, $input['tags']);
        Toast::success(__('Tags saved.'));

        return back();
    }

    public function dealTags(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, DealController::EDIT, $deal);
        $input = $request->validate(['tags' => ['present', 'array', 'max:100'], 'tags.*' => ['string', 'size:26']]);
        $this->content->syncTags(CrmEntityType::Deal, $deal->id, $input['tags']);
        Toast::success(__('Tags saved.'));

        return back();
    }

    public function leadCustomValues(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, LeadController::EDIT, $lead);
        $input = $request->validate(['values' => ['present', 'array']]);
        $this->content->setCustomValues(CrmEntityType::Lead, $lead->id, $input['values']);
        Toast::success(__('Custom fields saved.'));

        return back();
    }

    public function dealCustomValues(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, DealController::EDIT, $deal);
        $input = $request->validate(['values' => ['present', 'array']]);
        $this->content->setCustomValues(CrmEntityType::Deal, $deal->id, $input['values']);
        Toast::success(__('Custom fields saved.'));

        return back();
    }

    public function leadAttachment(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, LeadController::EDIT, $lead);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx,csv,txt']]);
        $this->content->attach(CrmEntityType::Lead, $lead->id, $input['file'], $this->support->membership($actor));
        Toast::success(__('Attachment uploaded.'));

        return back();
    }

    public function dealAttachment(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, DealController::EDIT, $deal);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx,csv,txt']]);
        $this->content->attach(CrmEntityType::Deal, $deal->id, $input['file'], $this->support->membership($actor));
        Toast::success(__('Attachment uploaded.'));

        return back();
    }

    public function customerNote(Request $request, CustomerProfile $customerProfile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, CustomerLifecycleController::MANAGE, $customerProfile);
        $input = $request->validate(['body' => ['required', 'string', 'max:20000'], 'pinned' => ['sometimes', 'boolean']]);
        [$type, $entityId] = $this->customerEntity($customerProfile);
        $this->content->addNote($input['body'], [$type->value.'_id' => $entityId], $this->support->membership($actor), (bool) ($input['pinned'] ?? false));
        Toast::success(__('Note added.'));

        return back();
    }

    public function customerTags(Request $request, CustomerProfile $customerProfile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, CustomerLifecycleController::MANAGE, $customerProfile);
        $input = $request->validate(['tags' => ['present', 'array', 'max:100'], 'tags.*' => ['string', 'size:26']]);
        [$type, $entityId] = $this->customerEntity($customerProfile);
        $this->content->syncTags($type, $entityId, $input['tags']);
        Toast::success(__('Tags saved.'));

        return back();
    }

    public function customerCustomValues(Request $request, CustomerProfile $customerProfile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, CustomerLifecycleController::MANAGE, $customerProfile);
        $input = $request->validate(['values' => ['present', 'array']]);
        [$type, $entityId] = $this->customerEntity($customerProfile);
        $this->content->setCustomValues($type, $entityId, $input['values']);
        Toast::success(__('Custom fields saved.'));

        return back();
    }

    public function customerAttachment(Request $request, CustomerProfile $customerProfile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, CustomerLifecycleController::MANAGE, $customerProfile);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx,csv,txt']]);
        [$type, $entityId] = $this->customerEntity($customerProfile);
        $this->content->attach($type, $entityId, $input['file'], $this->support->membership($actor));
        Toast::success(__('Attachment uploaded.'));

        return back();
    }

    public function downloadLead(Request $request, Lead $lead, Attachment $attachment): RedirectResponse
    {
        $this->authorizeLinkedAttachment($request, $attachment, CrmEntityType::Lead, $lead, LeadController::VIEW);

        return redirect()->away($this->storage->temporaryUrl($attachment->path, now()->addMinutes(5)));
    }

    public function downloadDeal(Request $request, Deal $deal, Attachment $attachment): RedirectResponse
    {
        $this->authorizeLinkedAttachment($request, $attachment, CrmEntityType::Deal, $deal, DealController::VIEW);

        return redirect()->away($this->storage->temporaryUrl($attachment->path, now()->addMinutes(5)));
    }

    public function downloadCustomer(Request $request, CustomerProfile $customerProfile, Attachment $attachment): RedirectResponse
    {
        [$type, $entityId] = $this->customerEntity($customerProfile);
        abort_unless($attachment->entity_type === $type && $attachment->entity_id === $entityId, 404);
        $this->records->authorize($this->support->actor($request), CustomerLifecycleController::VIEW, $customerProfile);

        return redirect()->away($this->storage->temporaryUrl($attachment->path, now()->addMinutes(5)));
    }

    public function destroyLeadAttachment(Request $request, Lead $lead, Attachment $attachment): RedirectResponse
    {
        $this->authorizeLinkedAttachment($request, $attachment, CrmEntityType::Lead, $lead, LeadController::EDIT);
        $this->content->deleteAttachment($attachment);
        Toast::success(__('Attachment deleted.'));

        return back();
    }

    public function destroyDealAttachment(Request $request, Deal $deal, Attachment $attachment): RedirectResponse
    {
        $this->authorizeLinkedAttachment($request, $attachment, CrmEntityType::Deal, $deal, DealController::EDIT);
        $this->content->deleteAttachment($attachment);
        Toast::success(__('Attachment deleted.'));

        return back();
    }

    public function destroyCustomerAttachment(Request $request, CustomerProfile $customerProfile, Attachment $attachment): RedirectResponse
    {
        [$type, $entityId] = $this->customerEntity($customerProfile);
        abort_unless($attachment->entity_type === $type && $attachment->entity_id === $entityId, 404);
        $this->records->authorize($this->support->actor($request), CustomerLifecycleController::MANAGE, $customerProfile);
        $this->content->deleteAttachment($attachment);
        Toast::success(__('Attachment deleted.'));

        return back();
    }

    private function authorizeLinkedAttachment(Request $request, Attachment $attachment, CrmEntityType $type, Lead|Deal $record, string $permission): void
    {
        abort_unless($attachment->entity_type === $type && $attachment->entity_id === $record->getKey(), 404);
        $this->records->authorize($this->support->actor($request), $permission, $record);
    }

    /** @return array{CrmEntityType, string} */
    private function customerEntity(CustomerProfile $profile): array
    {
        if ($profile->contact_id !== null) {
            return [CrmEntityType::Contact, $profile->contact_id];
        }

        abort_if($profile->company_id === null, 404);

        return [CrmEntityType::Company, $profile->company_id];
    }
}
