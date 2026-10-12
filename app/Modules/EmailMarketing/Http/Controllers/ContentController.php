<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Modules\EmailMarketing\Actions\ContentComposer;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Live preview of the block editor. Renders what is sent with made-up recipient data; stores nothing. */
final class ContentController
{
    public function __construct(
        private readonly ContentComposer $composer,
        private readonly EmailMarketingSupport $support,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $this->support->actor($request);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'blocks' => ['present', 'array', 'max:50'],
        ]);

        try {
            $blocks = ContentBlocks::normalize($data['blocks']);
            $subject = ContentBlocks::subject($data['subject'] ?? '', 'subject', false) ?? '';
        } catch (ValidationException $exception) {
            return response()->json(['valid' => false, 'errors' => $exception->errors()], 200);
        }

        $email = $this->composer->sample($blocks, $subject, $data['preheader'] ?? null);

        return response()->json([
            'valid' => true,
            'errors' => [],
            'subject' => $email->subject,
            'html' => $email->html,
            'text' => $email->text,
        ]);
    }
}
