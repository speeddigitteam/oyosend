<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\ProvidesPostOptions;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The choices a platform offers one account for a new post (TikTok's creator info: privacy levels, whether the
 * creator disabled comments, duets or stitches, the longest video), for the composer's per-account settings.
 * Reads only. The answer never carries a token, and a platform failure becomes our own short message.
 *
 * The route requires `social.posts.view`; the member must also be able to write posts, and the account must be
 * one the composer offers them (not disconnected, on their publish allow-list). Another workspace's id is a 404.
 */
final class PostOptionsController
{
    public function __construct(
        private readonly SocialSupport $support,
        private readonly SocialPlatforms $platforms,
        private readonly AccountManager $accounts,
    ) {}

    public function __invoke(Request $request, string $account): JsonResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($actor->can(PostController::CREATE) || $actor->can(PostController::EDIT), 403);

        $record = SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)->findOrFail($account);
        $this->support->assertAccounts($actor, AccountAllowList::PUBLISH, [$record->id]);

        $platform = $this->platforms->find($record->platform);
        abort_unless($platform instanceof ProvidesPostOptions, 404);

        if (! $platform->availability()->available) {
            return $this->unavailable(__('This platform is not available.'));
        }

        try {
            $options = $platform->postOptions($this->accounts->credentials($record));
        } catch (PublishFailed $failure) {
            return $this->unavailable($this->message($failure));
        } catch (Throwable $exception) {
            // Nothing from the exception is shown or stored: it may carry request details.
            Log::warning('Social post options could not be read.', ['account' => $record->id, 'type' => $exception::class]);

            return $this->unavailable(__('The posting settings of this account could not be loaded. Try again in a moment.'));
        }

        return response()->json([
            'state' => 'ok',
            'options' => [
                'privacyLevels' => $options['privacy_levels'],
                'commentDisabled' => $options['comment_disabled'],
                'duetDisabled' => $options['duet_disabled'],
                'stitchDisabled' => $options['stitch_disabled'],
                'maxVideoSeconds' => $options['max_video_seconds'],
                'nickname' => $options['nickname'],
            ],
        ]);
    }

    private function unavailable(string $message): JsonResponse
    {
        return response()->json(['state' => 'unavailable', 'message' => $message]);
    }

    /** Our own words for the failure; the platform's text is never passed on. */
    private function message(PublishFailed $failure): string
    {
        if ($failure->reauthorize) {
            return __('This account must be connected again before its posting settings can be loaded.');
        }

        if ($failure->retryable) {
            return __('The platform could not be reached. Try again in a moment.');
        }

        if (str_starts_with($failure->errorCode, 'tiktok_spam_risk') || $failure->errorCode === 'tiktok_reached_active_user_cap') {
            return __('TikTok says this account cannot post more right now. Try again later.');
        }

        return __('The posting settings of this account could not be loaded. Try again in a moment.');
    }
}
