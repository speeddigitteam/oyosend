<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;

/**
 * A multi-photo Page post: every photo is uploaded unpublished (`{page}/photos`, `published=false`),
 * then one `{page}/feed` call attaches them with `attached_media`. The unpublished uploads are invisible,
 * so a failure while uploading is safe to retry; only the final feed call can be an unknown outcome.
 */
final class FacebookPhotoPublisher
{
    public function __construct(private readonly FacebookGraph $graph) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $page = $account->externalId;
        $photos = $payload->media;

        if ($photos === []) {
            throw PublishFailed::rejected(__('A photo post needs at least one photo.'), 'media_missing');
        }

        /** @var list<string> $uploaded */
        $uploaded = array_values(array_filter((array) ($session->meta['photos'] ?? []), 'is_string'));
        $first = true;

        for ($index = count($uploaded); $index < count($photos); $index++) {
            if (! $first && microtime(true) >= $deadline) {
                return new PublishProgress('uploading', null, $index, ['photos' => $uploaded]);
            }

            $first = false;
            $photo = $photos[$index];
            $fields = ['published' => 'false'];

            if ($photo->altText !== null && $photo->altText !== '') {
                $fields['alt_text_custom'] = $photo->altText;
            }

            $contents = $photo->contents();
            $reply = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)
                ->attach('source', $contents, $photo->name)
                ->post($this->graph->url($page.'/photos'), $fields));
            $id = $reply->json('id');

            if (! is_string($id) && ! is_int($id)) {
                throw PublishFailed::rejected(__('Facebook did not return an id for an uploaded photo.'), 'missing_photo_id');
            }

            $uploaded[] = (string) $id;
        }

        $fields = ['message' => $payload->linkUrl === null ? $payload->text : trim($payload->text."\n\n".$payload->linkUrl)];

        foreach ($uploaded as $position => $id) {
            $fields['attached_media['.$position.']'] = json_encode(['media_fbid' => $id], JSON_THROW_ON_ERROR);
        }

        $response = $this->graph->send(fn () => $this->graph->request()->withToken($account->accessToken)->asForm()
            ->post($this->graph->url($page.'/feed'), $fields));
        $this->graph->ensure($response);
        $postId = $response->json('id');

        if (! is_string($postId) || $postId === '') {
            throw PublishFailed::unknownOutcome(__('Facebook accepted the request but returned no post id. Check the Page before trying again.'), 'missing_post_id');
        }

        return PublishProgress::published(new PublishedPost($postId, 'https://www.facebook.com/'.$postId));
    }
}
