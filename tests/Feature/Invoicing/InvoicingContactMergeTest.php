<?php

declare(strict_types=1);

use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\ContactMerger;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\RecurringManager;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringTemplate;

it('repoints invoices and recurring templates during a core contact merge and keeps the issued snapshot', function () {
    $tenant = $this->createTenant('invoicing-merge');
    $this->entitle($tenant, 'invoicing');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $directory = app(ContactDirectory::class);
        $canonical = $directory->createContact(['first_name' => 'Canonical', 'email' => 'canonical@example.test'], $owner);
        $duplicate = $directory->createContact(['first_name' => 'Duplicate', 'email' => 'duplicate@example.test'], $owner);
        $line = [['name' => 'Service', 'quantity_milli' => 1000, 'unit_price_amount' => 1_000]];

        $manager = app(InvoiceManager::class);
        $issued = $manager->issue($manager->create(['contact_id' => $duplicate->id, 'currency' => 'BDT'], $line, $owner));
        $draft = $manager->create(['contact_id' => $duplicate->id, 'currency' => 'BDT'], $line, $owner);
        $template = app(RecurringManager::class)->create([
            'contact_id' => $duplicate->id, 'name' => 'Retainer', 'frequency' => 'monthly', 'every' => 1,
            'currency' => 'BDT', 'starts_on' => '2027-01-01',
        ], $line, $owner);

        app(ContactMerger::class)->merge($canonical, $duplicate, []);

        expect(Invoice::query()->where('contact_id', $canonical->id)->count())->toBe(2)
            ->and(Invoice::query()->where('contact_id', $duplicate->id)->exists())->toBeFalse()
            ->and($template->refresh()->contact_id)->toBe($canonical->id)
            ->and(RecurringTemplate::query()->where('contact_id', $duplicate->id)->exists())->toBeFalse()
            // The issued document still reads as it was issued.
            ->and($issued->refresh()->customer_name)->toBe('Duplicate')
            ->and($issued->customer_email)->toBe('duplicate@example.test')
            ->and($draft->refresh()->contact_id)->toBe($canonical->id);
    });
});
