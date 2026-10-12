<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Http\Requests\MediaUpdateRequest;
use App\Modules\Social\Http\Requests\MediaUploadRequest;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\PostMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MediaController
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly SocialSupport $support,
        private readonly SocialPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['kind' => ['nullable', 'in:image,video'], 'q' => ['nullable', 'string', 'max:100']]);

        $media = Media::query()
            ->when($input['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($input['q'] ?? null, fn ($query, string $term) => $query->where('name', 'like', Like::contains($term)))
            ->latest()
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Media $item): array => [
                ...$this->presenter->media($item),
                'inUse' => PostMedia::query()->where('media_id', $item->id)->exists(),
            ]);

        return Inertia::render('Modules/Social/Pages/Media/Index', [
            'media' => $media,
            'filters' => ['kind' => $input['kind'] ?? null, 'q' => $input['q'] ?? null],
            'canManage' => $actor->can('social.media.manage'),
            'accept' => [
                'mimes' => [...(array) config('social.media.image_mimes'), ...(array) config('social.media.video_mimes')],
                'imageMaxKb' => (int) config('social.media.image_max_kb'),
                'videoMaxKb' => (int) config('social.media.video_max_kb'),
            ],
        ]);
    }

    public function store(MediaUploadRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $stored = 0;
        $errors = [];

        /** @var list<UploadedFile> $files */
        $files = $request->file('files', []);

        foreach ($files as $index => $file) {
            try {
                $this->library->store($file, $membership);
                $stored++;
            } catch (ValidationException $exception) {
                $errors['files.'.$index] = $file->getClientOriginalName().': '.(string) collect($exception->errors())->flatten()->first();
            }
        }

        if ($errors !== [] && $stored === 0) {
            throw ValidationException::withMessages($errors);
        }

        Toast::success(trans_choice(':count file uploaded.|:count files uploaded.', $stored, ['count' => $stored]));

        return $errors === [] ? back() : back()->withErrors($errors);
    }

    public function update(MediaUpdateRequest $request, string $media): RedirectResponse
    {
        $this->support->actor($request);
        $item = Media::query()->findOrFail($media);
        $this->library->update($item, $request->validated('alt_text'), $request->validated('name'));
        Toast::success(__('File saved.'));

        return back();
    }

    public function destroy(Request $request, string $media): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->delete(Media::query()->findOrFail($media));
        Toast::success(__('File deleted.'));

        return back();
    }

    /** The file itself, for members allowed to see the library. Never publicly reachable. */
    public function file(Request $request, string $media): HttpResponse
    {
        $this->support->actor($request);
        $item = Media::query()->findOrFail($media);
        $contents = $this->library->contents($item);

        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $item->mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="'.addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $item->name) ?? 'file', '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
