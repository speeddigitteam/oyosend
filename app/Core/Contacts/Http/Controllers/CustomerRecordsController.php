<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\CustomerRecordRegistry;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer 360: what every app the member may use holds about one contact (ADR 0020). */
final class CustomerRecordsController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly CustomerRecordRegistry $registry,
    ) {}

    public function show(Request $request, Contact $contact): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, ContactController::VIEW, $contact);
        $input = $request->validate(['limit' => ['nullable', 'integer', 'between:1,'.CustomerQuery::MAX_LIMIT]]);

        return response()->json([
            'contact' => ['id' => $contact->id, 'name' => $contact->fullName()],
            'sections' => $this->registry->sectionsFor($actor, $contact, (int) ($input['limit'] ?? CustomerQuery::DEFAULT_LIMIT)),
        ]);
    }
}
