<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Support\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Private ticket files. They live under the workspace's own storage root, are named by the storage layer,
 * and are only ever streamed back through an authorized controller action as a download.
 */
final class AttachmentStore
{
    public const int MAX_FILES = 5;

    /** Kilobytes. */
    public const int MAX_SIZE_KB = 10240;

    /** @var list<string> Extensions accepted; the content type is taken from the upload, never trusted for display. */
    public const array EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    public function __construct(private readonly TenantStorage $storage) {}

    /**
     * Writes the files and returns their metadata. Call {@see discard()} when the transaction that was to
     * record them fails.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    public function store(array $files): array
    {
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['attachments' => __('Attach at most :max files.', ['max' => self::MAX_FILES])]);
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());

                if (! $file->isValid() || ! in_array($extension, self::EXTENSIONS, true) || $file->getSize() > self::MAX_SIZE_KB * 1024) {
                    throw ValidationException::withMessages(['attachments' => __('A file is not allowed or is too large.')]);
                }

                $stored[] = [
                    'path' => $this->storage->putFile('support/attachments', $file),
                    'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'mime' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                ];
            }
        } catch (Throwable $exception) {
            $this->discard($stored);

            throw $exception;
        }

        return $stored;
    }

    /** @param list<array{path: string, name: string, mime: string, size: int}> $stored */
    public function discard(array $stored): void
    {
        foreach ($stored as $file) {
            $this->storage->delete($file['path']);
        }
    }

    public function contents(Attachment $attachment): ?string
    {
        return $this->storage->get($attachment->path);
    }
}
