<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Actions\UnsubscribeLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The public unsubscribe page. Stateless: no session, cookies or CSRF token.
 *
 * Opening the link (GET) changes nothing, so mail scanners and previews cannot unsubscribe anyone. The
 * change is a POST, which is also what mail clients send for one-click unsubscribe (RFC 8058).
 * The token is the only credential; it is looked up by hash and answers identically for every invalid value.
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly UnsubscribeLinks $links,
        private readonly TenantContext $context,
    ) {}

    public function show(string $token): View
    {
        $this->locale();
        $valid = $this->links->find($token) !== null;

        return view('communication.unsubscribe', [
            'state' => $valid ? 'confirm' : 'invalid',
            'workspace' => $this->context->require()->name,
            'token' => $valid ? $token : null,
        ]);
    }

    public function store(Request $request, string $token): View
    {
        $this->locale();
        $row = $this->links->find($token);

        if ($row !== null) {
            $this->links->redeem($row);
        }

        return view('communication.unsubscribe', [
            'state' => $row === null ? 'invalid' : 'done',
            'workspace' => $this->context->require()->name,
            'token' => null,
        ]);
    }

    private function locale(): void
    {
        App::setLocale($this->context->require()->locale);
    }
}
