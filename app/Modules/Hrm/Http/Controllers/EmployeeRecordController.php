<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\EmployeeRecords;
use App\Modules\Hrm\Http\Requests\AssetRequest;
use App\Modules\Hrm\Http\Requests\AssetReturnRequest;
use App\Modules\Hrm\Http\Requests\DocumentRequest;
use App\Modules\Hrm\Http\Requests\EmergencyContactRequest;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use App\Modules\Hrm\Support\HrmPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/** Emergency contacts, private documents and assets of one employee. */
final class EmployeeRecordController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly EmployeeRecords $recordsManager,
        private readonly HrmControllerSupport $support,
    ) {}

    public function storeContact(EmergencyContactRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorizeManage($request, $employee);
        $this->recordsManager->addContact($employee, $request->validated());
        Toast::success(__('Emergency contact added.'));

        return back();
    }

    public function updateContact(EmergencyContactRequest $request, Employee $employee, EmergencyContact $contact): RedirectResponse
    {
        $this->authorizeManage($request, $employee);
        abort_unless($contact->employee_id === $employee->id, 404);
        $this->recordsManager->updateContact($contact, $request->validated());
        Toast::success(__('Emergency contact saved.'));

        return back();
    }

    public function destroyContact(Request $request, Employee $employee, EmergencyContact $contact): RedirectResponse
    {
        $this->authorizeManage($request, $employee);
        abort_unless($contact->employee_id === $employee->id, 404);
        $this->recordsManager->deleteContact($contact);
        Toast::success(__('Emergency contact removed.'));

        return back();
    }

    public function storeDocument(DocumentRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_MANAGE, $employee);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        /** @var array{type: string, title: string, expires_on?: string|null} $attributes */
        $attributes = $request->safe()->only(['type', 'title', 'expires_on']);
        $this->recordsManager->addDocument($employee, $file, $attributes, $this->support->membership($actor));
        Toast::success(__('Document uploaded.'));

        return back();
    }

    public function downloadDocument(Request $request, Employee $employee, EmployeeDocument $document): Response
    {
        $actor = $this->support->actor($request);
        abort_unless($document->employee_id === $employee->id, 404);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_VIEW, $employee);
        $own = $this->support->ownEmployee($actor);

        // Private files: the personal-data permission over the record, or the employee themself.
        abort_unless(($own !== null && $own->id === $employee->id) || $this->records->allows($actor, HrmPermissions::PERSONAL_VIEW, $employee), 404);

        $contents = $this->recordsManager->documentContents($document);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($document->original_name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyDocument(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->authorizeManage($request, $employee);
        abort_unless($document->employee_id === $employee->id, 404);
        $this->recordsManager->deleteDocument($document);
        Toast::success(__('Document removed.'));

        return back();
    }

    public function storeAsset(AssetRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_MANAGE, $employee);
        $this->recordsManager->assignAsset($employee, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Asset assigned.'));

        return back();
    }

    public function returnAsset(AssetReturnRequest $request, Employee $employee, EmployeeAsset $asset): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_MANAGE, $employee);
        abort_unless($asset->employee_id === $employee->id, 404);
        $this->recordsManager->returnAsset($asset, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Asset returned.'));

        return back();
    }

    private function authorizeManage(Request $request, Employee $employee): void
    {
        $this->records->authorize($this->support->actor($request), HrmPermissions::EMPLOYEES_MANAGE, $employee);
    }
}
