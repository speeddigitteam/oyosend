<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Company;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The document template and the uploaded letterhead ("pad"): a workspace picks one of four
 * layouts, and the Letterhead layout lays invoices on its own header and footer bands.
 */
beforeEach(function () {
    $this->disk = Storage::fake('local');

    $this->tenant = $this->createTenant('inv-doc', ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'name' => 'Seller']);
    $this->entitle($this->tenant, 'invoicing');
    $this->owner = $this->ownerOf($this->tenant);
    $this->membership = $this->membershipOf($this->tenant, $this->owner);
    $this->url = fn (string $path = '') => $this->tenantUrl($this->tenant, '/invoicing'.$path);

    $this->band = fn (string $name = 'pad.png'): UploadedFile => UploadedFile::fake()->image($name, 1600, 400);

    $this->issued = fn (): Invoice => $this->inTenant($this->tenant, function (): Invoice {
        $company = Company::factory()->create(['name' => 'Buyer Co']);
        $manager = app(InvoiceManager::class);

        return $manager->issue($manager->create(
            ['company_id' => $company->id, 'currency' => 'BDT'],
            [['name' => 'Design', 'quantity_milli' => 1000, 'unit_price_amount' => 100_000]],
            $this->membership,
        ));
    });
});

it('saves the chosen document template', function () {
    $this->actingAsOwner($this->tenant)
        ->put(($this->url)('/settings'), [
            'invoice_prefix' => 'INV-', 'credit_note_prefix' => 'CN-', 'number_padding' => 4, 'fiscal_year_numbering' => false,
            'fiscal_year_start_month' => 1, 'default_due_days' => 14, 'default_terms' => null, 'default_notes' => null,
            'reminders_enabled' => false, 'reminder_before_days' => 3, 'reminder_overdue_every_days' => 7,
            'invoice_accepted_quotes' => true, 'document_template' => 'compact',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, function (): void {
        expect(app(InvoicingSettings::class)->current()->document_template->value)->toBe('compact');
    });

    $this->actingAsOwner($this->tenant)
        ->put(($this->url)('/settings'), ['document_template' => 'nope'])
        ->assertSessionHasErrors('document_template');
});

it('uploads a letterhead band into the workspace directory and records it', function () {
    $this->actingAsOwner($this->tenant)
        ->from(($this->url)('/settings'))
        ->post(($this->url)('/settings/letterhead/header'), ['file' => ($this->band)()])
        ->assertRedirect(($this->url)('/settings'))
        ->assertSessionHasNoErrors();

    $path = $this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->current()->letterhead_header_path);

    expect($path)->toMatch('#^invoicing/letterhead/[A-Za-z0-9]{40}\.png$#');
    $this->disk->assertExists("tenants/{$this->tenant->id}/{$path}");
    expect($this->auditTrail($this->tenant, 'invoicing.letterhead.updated')->count())->toBe(1);
});

it('rejects a non-image letterhead and an unknown slot', function () {
    $this->actingAsOwner($this->tenant)
        ->post(($this->url)('/settings/letterhead/header'), ['file' => UploadedFile::fake()->create('pad.pdf', 50, 'application/pdf')])
        ->assertSessionHasErrors('file');

    $this->actingAsOwner($this->tenant)
        ->post(($this->url)('/settings/letterhead/sidebar'), ['file' => ($this->band)()])
        ->assertNotFound();
});

it('removes a letterhead band and deletes the file', function () {
    $this->actingAsOwner($this->tenant)
        ->post(($this->url)('/settings/letterhead/footer'), ['file' => ($this->band)()])
        ->assertRedirect();

    $path = $this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->current()->letterhead_footer_path);
    $this->disk->assertExists("tenants/{$this->tenant->id}/{$path}");

    $this->actingAsOwner($this->tenant)
        ->delete(($this->url)('/settings/letterhead/footer'))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->current()->letterhead_footer_path))->toBeNull();
    $this->disk->assertMissing("tenants/{$this->tenant->id}/{$path}");
});

it('previews a sample document in the chosen template without persisting anything', function () {
    $this->actingAsOwner($this->tenant)
        ->get(($this->url)('/settings/document-preview?template=modern'))
        ->assertOk()
        ->assertSee('data-template="modern"', false)
        ->assertSee('Sample invoice');

    // An unknown template falls back to the saved one (classic).
    $this->actingAsOwner($this->tenant)
        ->get(($this->url)('/settings/document-preview?template=nope'))
        ->assertOk()
        ->assertSee('data-template="classic"', false);

    $this->inTenant($this->tenant, fn () => expect(Invoice::query()->count())->toBe(0));
});

it('lays the document on the uploaded bands only for the letterhead template', function () {
    $invoice = ($this->issued)();

    // Classic (default): no letterhead bands, seller block present.
    $this->actingAsOwner($this->tenant)
        ->get(($this->url)("/invoices/{$invoice->id}/document"))
        ->assertOk()
        ->assertSee('data-template="classic"', false)
        ->assertDontSee('letterhead-header', false);

    // Switch to letterhead and upload both bands.
    $this->actingAsOwner($this->tenant)->post(($this->url)('/settings/letterhead/header'), ['file' => ($this->band)('head.png')]);
    $this->actingAsOwner($this->tenant)->post(($this->url)('/settings/letterhead/footer'), ['file' => ($this->band)('foot.png')]);
    $this->inTenant($this->tenant, fn () => app(InvoicingSettings::class)->update(['document_template' => 'letterhead']));

    $this->actingAsOwner($this->tenant)
        ->get(($this->url)("/invoices/{$invoice->id}/document"))
        ->assertOk()
        ->assertSee('data-template="letterhead"', false)
        ->assertSee('letterhead-header', false)
        ->assertSee('letterhead-footer', false)
        ->assertSee('data:image/png;base64,', false);
});
