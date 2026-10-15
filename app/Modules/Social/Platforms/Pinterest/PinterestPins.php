<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Pinterest;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use Illuminate\Http\Client\Response;

/**
 * `POST /v5/pins`: the one call that makes a Pin public. It has no idempotency key, so a lost answer or a
 * 5xx is an unknown outcome and is never repeated blindly. The board is the connected account.
 */
final class PinterestPins
{
    /** Seconds; a base64 carousel body can be tens of megabytes, but the job itself ends after 60. */
    private const int CREATE_TIMEOUT = 40;

    public function __construct(private readonly PinterestApi $api) {}

    /**
     * @param  array<string, mixed>  $mediaSource
     *
     * @throws PublishFailed
     */
    public function create(AccountCredentials $account, PublishPayload $payload, array $mediaSource, ?MediaFile $altFrom = null): PublishedPost
    {
        $body = ['board_id' => $account->externalId, 'media_source' => $mediaSource];
        $title = PinterestMediaRules::title($payload->text);
        $description = PinterestMediaRules::description($payload->text);

        if ($title !== null) {
            $body['title'] = $title;
        }

        if ($description !== null) {
            $body['description'] = $description;
        }

        if ($payload->linkUrl !== null && $payload->linkUrl !== '') {
            $body['link'] = $payload->linkUrl;
        }

        $alt = $altFrom?->altText !== null ? trim($altFrom->altText) : '';

        if ($alt !== '') {
            $body['alt_text'] = mb_substr($alt, 0, 500);
        }

        $response = $this->api->send(fn (): Response => $this->api->request()->timeout(self::CREATE_TIMEOUT)
            ->withToken($account->accessToken)->asJson()->post($this->api->url('pins'), $body));

        if (! $response->successful()) {
            throw PinterestApi::failure($response, __('The Pinterest board no longer exists or this account cannot pin to it. Connect the board again.'));
        }

        $id = $response->json('id');

        if (! is_string($id) && ! is_int($id)) {
            throw PublishFailed::unknownOutcome(__('Pinterest accepted the request but returned no Pin id. Check the board before trying again.'), 'missing_post_id');
        }

        $id = (string) $id;

        return new PublishedPost($id, 'https://www.pinterest.com/pin/'.rawurlencode($id).'/');
    }

    /**
     * One image as `image_base64`, or 2+ as a `multiple_image_base64` carousel. Base64 needs no public URL,
     * so the private library file is never exposed.
     *
     * @param  list<MediaFile>  $images
     * @return array<string, mixed>
     *
     * @throws PublishFailed
     */
    public static function imageSource(array $images): array
    {
        $items = array_map(static fn (MediaFile $image): array => self::encoded($image), $images);

        if (count($items) === 1) {
            return ['source_type' => 'image_base64', ...$items[0]];
        }

        return ['source_type' => 'multiple_image_base64', 'items' => $items];
    }

    /**
     * @return array{content_type: string, data: string}
     *
     * @throws PublishFailed
     */
    public static function encoded(MediaFile $image): array
    {
        $mime = strtolower($image->mime);

        if (! in_array($mime, PinterestMediaRules::imageMimes(), true)) {
            throw PublishFailed::rejected(__('Pinterest accepts JPEG and PNG images only.'), 'unsupported_media');
        }

        $bytes = $image->contents();

        if ($bytes === '') {
            throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
        }

        return ['content_type' => $mime, 'data' => base64_encode($bytes)];
    }
}
