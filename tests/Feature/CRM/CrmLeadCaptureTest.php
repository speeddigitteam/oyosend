<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Contacts\ContactDirectory;
use App\Modules\CRM\Actions\SalesOrderManager;
use App\Modules\CRM\Automation\Actions\CreateLeadAction;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderStatusChanged;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('crm-capture');
    $this->entitle($this->tenant, 'crm');
    $this->contact = $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->createContact(['first_name' => 'Rahim', 'phone' => '+8801711000001'], null));
    $this->create = fn (array $input, string $key) => $this->inTenant($this->tenant, fn () => app(CreateLeadAction::class)->execute($input, $key));
});

it('creates a lead for a contact, once per idempotency key', function () {
    $first = ($this->create)(['contact_id' => $this->contact->id, 'title' => 'WhatsApp enquiry'], 'wf:run1:step1');
    $again = ($this->create)(['contact_id' => $this->contact->id, 'title' => 'WhatsApp enquiry'], 'wf:run1:step1');

    expect($first['created'])->toBeTrue()
        ->and($again)->toEqual($first);

    $this->inTenant($this->tenant, function () use ($first): void {
        $lead = Lead::query()->sole();

        expect($lead->id)->toBe($first['lead_id'])
            ->and($lead->title)->toBe('WhatsApp enquiry')
            ->and($lead->status)->toBe(LeadStatus::New);
    });
});

it('returns the open lead instead of a second one, unless told otherwise', function () {
    $first = ($this->create)(['contact_id' => $this->contact->id, 'title' => 'First'], 'k1');
    $second = ($this->create)(['contact_id' => $this->contact->id, 'title' => 'Second'], 'k2');
    $forced = ($this->create)(['contact_id' => $this->contact->id, 'title' => 'Third', 'skip_if_open' => false], 'k3');

    expect($second)->toEqual(['lead_id' => $first['lead_id'], 'created' => false])
        ->and($forced['created'])->toBeTrue();
    $this->inTenant($this->tenant, fn () => expect(Lead::query()->count())->toBe(2));
});

it('refuses a lead without a contact or a title', function () {
    expect(fn () => ($this->create)(['contact_id' => $this->contact->id, 'title' => '  '], 'k4'))->toThrow(ValidationException::class);
});

it('registers the lead action and the order triggers for workflows', function () {
    $registry = app(AutomationRegistry::class);

    expect(array_keys($registry->registeredActions()))->toContain('crm.lead.create')
        ->and(array_keys($registry->registeredTriggers()))->toContain('crm.quote.accepted', 'crm.sales_order.status_changed');
});

it('announces a fulfilled sales order', function () {
    Event::fake([SalesOrderStatusChanged::class]);

    $order = $this->inTenant($this->tenant, fn () => SalesOrder::query()->forceCreate([
        'contact_id' => $this->contact->id,
        'number' => 'SO-TEST1',
        'status' => SalesOrderStatus::Confirmed,
        'currency' => 'BDT',
        'subtotal_amount' => 10000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 10000,
        'ordered_at' => now(),
    ]));

    $this->inTenant($this->tenant, fn () => app(SalesOrderManager::class)->transition($order, SalesOrderStatus::Fulfilled));

    Event::assertDispatched(SalesOrderStatusChanged::class, fn (SalesOrderStatusChanged $event): bool => $event->payload()['status'] === 'fulfilled'
        && $event->payload()['sales_order_number'] === 'SO-TEST1'
        && $event->payload()['contact_id'] === $this->contact->id);
});
