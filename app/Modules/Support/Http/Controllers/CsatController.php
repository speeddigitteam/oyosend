<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Actions\SurveyManager;
use App\Modules\Support\Models\CsatSurvey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public satisfaction survey. Stateless: it is loaded through `loadTenantCallbackRoutes()`, so there is no
 * session, no cookie, no CSRF token and no Inertia; the unguessable single-use token is the only credential.
 *
 * Viewing the page changes nothing. The answer is a POST. Every failure (malformed, unknown, answered,
 * superseded, expired) is the same 404 page, and the page shows only the workspace name, the ticket number
 * and its subject.
 */
final class CsatController
{
    public function __construct(
        private readonly SurveyManager $surveys,
        private readonly TenantContext $context,
    ) {}

    public function show(string $token): Response
    {
        $this->locale();
        $survey = $this->surveys->find($token);

        return $survey === null ? $this->invalid() : $this->form($survey);
    }

    public function store(Request $request, string $token): Response
    {
        $this->locale();
        $survey = $this->surveys->find($token);

        if ($survey === null) {
            return $this->invalid();
        }

        $input = $request->only(['rating', 'comment']);
        $validator = Validator::make($input, [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['rating.required' => __('Choose a rating from 1 to 5.'), 'rating.between' => __('Choose a rating from 1 to 5.'), 'rating.integer' => __('Choose a rating from 1 to 5.')]);

        if ($validator->fails()) {
            return $this->form($survey, $validator->errors()->all(), $input, 422);
        }

        try {
            $this->surveys->answer($token, (int) $input['rating'], isset($input['comment']) ? (string) $input['comment'] : null);
        } catch (NotFoundHttpException) {
            // A concurrent or repeated answer reaches the same 404 page as any invalid link.
            return $this->invalid();
        }

        return $this->hardened(response()->view('support.survey', [
            'state' => 'done',
            'workspace' => $this->context->require()->name,
        ]));
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function form(CsatSurvey $survey, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $this->hardened(response()->view('support.survey', [
            'state' => 'form',
            'workspace' => $this->context->require()->name,
            'number' => $survey->ticket->number,
            'subject' => $survey->ticket->subject,
            'errors' => $errors,
            'rating' => $old['rating'] ?? null,
            'comment' => $old['comment'] ?? '',
        ], $status));
    }

    private function invalid(): Response
    {
        return $this->hardened(response()->view('support.survey', [
            'state' => 'invalid',
            'workspace' => $this->context->require()->name,
        ], 404));
    }

    /** The page carries a credential in its URL: no framing, no referrer, no caching. */
    private function hardened(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function locale(): void
    {
        App::setLocale($this->context->require()->locale);
    }
}
