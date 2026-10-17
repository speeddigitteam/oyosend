<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Enums\AddressType;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Models\ContactAddress;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The invoice, delivery and other addresses of a company or a contact (ADR 0039). Whoever may change the parent may
 * change its addresses: every request is authorized against the parent record with its scope, and an address whose
 * parent is outside that scope - or deleted, or another workspace's - answers 404.
 */
final class ContactAddressController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly ContactDirectory $directory,
    ) {}

    public function storeForCompany(Request $request, Company $company): RedirectResponse
    {
        $this->records->authorize($this->actor($request), ContactController::MANAGE, $company);

        $this->directory->addAddress($company, $this->validated($request));

        Toast::success(__('Address added.'));

        return back();
    }

    public function storeForContact(Request $request, Contact $contact): RedirectResponse
    {
        $this->records->authorize($this->actor($request), ContactController::MANAGE, $contact);

        $this->directory->addAddress($contact, $this->validated($request));

        Toast::success(__('Address added.'));

        return back();
    }

    public function update(Request $request, ContactAddress $address): RedirectResponse
    {
        $this->authorizeParent($request, $address);

        $this->directory->updateAddress($address, $this->validated($request));

        Toast::success(__('Address saved.'));

        return back();
    }

    public function makeDefault(Request $request, ContactAddress $address): RedirectResponse
    {
        $this->authorizeParent($request, $address);

        $this->directory->makeDefaultAddress($address);

        Toast::success(__('Default address changed.'));

        return back();
    }

    public function destroy(Request $request, ContactAddress $address): RedirectResponse
    {
        $this->authorizeParent($request, $address);

        $this->directory->deleteAddress($address);

        Toast::success(__('Address deleted.'));

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user('web');

        return $actor;
    }

    private function authorizeParent(Request $request, ContactAddress $address): void
    {
        // A soft-deleted parent is not loaded, so its addresses are gone with it.
        $address->loadMissing(['company', 'contact']);
        $parent = $address->company ?? $address->contact;

        abort_if($parent === null, 404);

        $this->records->authorize($this->actor($request), ContactController::MANAGE, $parent);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'type' => ['required', 'string', Rule::enum(AddressType::class)],
            'label' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $filled = array_filter(
            array_intersect_key($input, array_flip(['label', 'name', 'email', 'phone', 'address', 'city', 'postal_code', 'country'])),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($filled === []) {
            throw ValidationException::withMessages([
                'address' => __('Fill in the address, or at least a name, phone or email for it.'),
            ]);
        }

        if (isset($input['email'])) {
            $input['email'] = mb_strtolower($input['email']);
        }

        // The form sends the whole address: a field left out is cleared, as in the company and contact forms.
        $attributes = [];

        foreach (ContactAddress::FIELDS as $field) {
            $attributes[$field] = $input[$field] ?? null;
        }

        if (array_key_exists('is_default', $input)) {
            $attributes['is_default'] = (bool) $input['is_default'];
        }

        return $attributes;
    }
}
