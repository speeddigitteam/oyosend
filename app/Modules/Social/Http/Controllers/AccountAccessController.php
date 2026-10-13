<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\PageInsightsCollector;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\AccountAccess;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The allow-list of one connected account and the manual "check access" of its platform tasks. */
final class AccountAccessController
{
    public function __construct(
        private readonly AccountAllowList $allowList,
        private readonly PageInsightsCollector $collector,
    ) {}

    public function update(Request $request, string $account): RedirectResponse
    {
        $record = SocialAccount::query()->findOrFail($account);
        $input = $request->validate([
            'entries' => ['present', 'array', 'max:100'],
            'entries.*.subject_type' => ['required', Rule::in([AccountAccess::MEMBERSHIP, AccountAccess::ROLE])],
            'entries.*.subject_id' => ['required', 'string', 'size:26'],
            'entries.*.can_publish' => ['boolean'],
            'entries.*.can_approve' => ['boolean'],
            'entries.*.can_analytics' => ['boolean'],
        ]);

        $this->allowList->replace($record, array_values($input['entries']));
        Toast::success($input['entries'] === []
            ? __('The account is open to everyone who has the matching permission.')
            : __('The allow-list was saved.'));

        return back();
    }

    public function check(string $account): RedirectResponse
    {
        $record = SocialAccount::query()->findOrFail($account);

        if ($record->status !== AccountStatus::Connected) {
            Toast::warning(__('Connect the account again before checking its access.'));

            return back();
        }

        $report = $this->collector->checkAccess($record);

        if ($report === null) {
            Toast::warning(__('The platform could not be asked right now. Try again later.'));
        } else {
            Toast::success(__('Access was checked.'));
        }

        return back();
    }
}
