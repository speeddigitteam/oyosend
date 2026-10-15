<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The documented Messenger Platform calls direct messages need, with the Page access token in the `Authorization`
 * header only (never in a log line, a message or an exception; `debug_token` takes it as its documented query
 * parameter, over TLS, as lead ads already does):
 *
 *  - `POST /{page-id}/messages`               send a text reply. Messenger: `recipient.id` = PSID,
 *                                             `messaging_type=RESPONSE`; Instagram: `recipient.id` = IGSID.
 *                                             https://developers.facebook.com/docs/messenger-platform/reference/send-api/
 *                                             https://developers.facebook.com/docs/messenger-platform/instagram/features/send-message/
 *  - `GET  /{page-id}?fields=instagram_business_account`  the Instagram professional account linked to the Page.
 *                                             https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/
 *  - `GET|POST|DELETE /{page-id}/subscribed_apps`  read and set the Page webhook fields (see FacebookPageSubscriptions).
 *  - `GET  /debug_token`                      the permissions the Page token holds (app access token).
 *
 * Needs `pages_messaging` and `pages_manage_metadata` (Instagram also `instagram_basic` and
 * `instagram_manage_messages`) approved through Meta app review. Written against the documented API; NOT run
 * against Meta's live API in this repository.
 */
final class FacebookMessaging
{
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private const array TOKEN_CODES = [102, 190, 459, 464, 467];

    /** "This message is sent outside of allowed window" (Messenger 2018278, Instagram 2534022). */
    private const array WINDOW_SUBCODES = [2018278, 2534022];

    /** The person cannot be reached: no matching user, not available, not receiving messages from the Page. */
    private const array RECIPIENT_SUBCODES = [2018001, 2018108, 2534014, 1545041];

    private const string MID = '/^[A-Za-z0-9_\-.:=+\/]{1,191}$/';

    public function __construct(private readonly Factory $http) {}

    /**
     * Send one text message as the Page and return the platform's message id.
     *
     * @throws FacebookMessagingException
     */
    public function send(string $pageId, string $token, string $platform, string $recipientId, string $text): string
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['text' => $text]];

        if ($platform === 'messenger') {
            // A reply to a message the person sent, inside the standard messaging window.
            $payload['messaging_type'] = 'RESPONSE';
        }

        $response = $this->call(fn (): Response => $this->client($token)->asJson()->post($this->url($pageId.'/messages'), $payload), sending: true);
        $mid = $response->json('message_id');

        if (! is_string($mid) || preg_match(self::MID, $mid) !== 1) {
            // Accepted but unidentified: the message may have been delivered, so it must not be sent again blindly.
            throw new FacebookMessagingException(
                FacebookMessagingException::UNAVAILABLE,
                __('Facebook accepted the message but returned no id. Check the conversation in Meta Business Suite before sending it again.'),
                'missing_message_id',
                maybeSent: true,
            );
        }

        return $mid;
    }

    /**
     * The Instagram professional account linked to the Page, or null when none is linked.
     *
     * @throws FacebookMessagingException
     */
    public function linkedInstagramAccount(string $pageId, string $token): ?string
    {
        $response = $this->call(fn (): Response => $this->client($token)->get($this->url($pageId), ['fields' => 'instagram_business_account']));
        $id = $response->json('instagram_business_account.id');

        return (is_string($id) || is_int($id)) && preg_match('/^\d{1,40}$/', (string) $id) === 1 ? (string) $id : null;
    }

    /**
     * The Page webhook fields this app receives now.
     *
     * @return list<string>
     *
     * @throws FacebookMessagingException
     */
    public function subscribedFields(string $pageId, string $token): array
    {
        return FacebookPageSubscriptions::fieldsOf($this->call(fn (): Response => $this->client($token)->get($this->url($pageId.'/subscribed_apps'))));
    }

    /**
     * Set the Page webhook fields this app receives (the full list: see {@see FacebookPageSubscriptions}). An empty
     * list removes the app from the Page.
     *
     * @param  list<string>  $fields
     *
     * @throws FacebookMessagingException
     */
    public function setSubscribedFields(string $pageId, string $token, array $fields): void
    {
        if ($fields === []) {
            $this->call(fn (): Response => $this->client($token)->delete($this->url($pageId.'/subscribed_apps')));

            return;
        }

        $response = $this->call(fn (): Response => $this->client($token)->asForm()->post($this->url($pageId.'/subscribed_apps'), [
            'subscribed_fields' => implode(',', $fields),
        ]));

        if ($response->json('success') === false) {
            throw new FacebookMessagingException(FacebookMessagingException::REJECTED, __('Facebook did not accept the subscription.'), 'subscription_rejected');
        }
    }

    /**
     * The permissions the Page token holds, or null when the platform did not say.
     *
     * @return list<string>|null
     *
     * @throws FacebookMessagingException
     */
    public function grantedScopes(string $token): ?array
    {
        $id = config('social.platforms.facebook.client_id');
        $secret = config('social.platforms.facebook.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        $response = $this->call(fn (): Response => $this->client($id.'|'.$secret)->get($this->url('debug_token'), ['input_token' => $token]));

        if ($response->json('data.is_valid') === false) {
            throw new FacebookMessagingException(FacebookMessagingException::TOKEN, $this->tokenMessage(), FacebookGraphErrors::TOKEN);
        }

        $scopes = $response->json('data.scopes');

        return is_array($scopes)
            ? array_values(array_filter(array_map(static fn ($scope): string => is_string($scope) ? $scope : '', $scopes), static fn (string $scope): bool => $scope !== ''))
            : null;
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws FacebookMessagingException
     */
    private function call(Closure $request, bool $sending = false): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            $text = $exception->getMessage();
            $neverSent = str_contains($text, 'Could not resolve host') || str_contains($text, 'Failed to connect')
                || str_contains($text, 'Connection refused') || str_contains($text, 'cURL error 6') || str_contains($text, 'cURL error 7');

            // The exception text can carry the URL; it is never passed on.
            throw new FacebookMessagingException(
                FacebookMessagingException::UNAVAILABLE,
                $sending && ! $neverSent
                    ? __('Facebook did not confirm the message. It may have been delivered: check the conversation in Meta Business Suite before sending it again.')
                    : __('Facebook could not be reached. Try again in a little while.'),
                $neverSent ? 'network_unreachable' : 'network_unconfirmed',
                maybeSent: $sending && ! $neverSent,
            );
        } catch (Throwable) {
            throw new FacebookMessagingException(FacebookMessagingException::UNAVAILABLE, __('Facebook could not be reached. Try again in a little while.'), 'transport_error');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->failure($response, $sending);
    }

    private function failure(Response $response, bool $sending): FacebookMessagingException
    {
        $code = (int) $response->json('error.code', 0);
        $subcode = (int) $response->json('error.error_subcode', 0);

        if (in_array($code, self::TOKEN_CODES, true) || $response->status() === 401) {
            return new FacebookMessagingException(FacebookMessagingException::TOKEN, $this->tokenMessage(), FacebookGraphErrors::TOKEN);
        }

        if (in_array($subcode, self::WINDOW_SUBCODES, true)) {
            return new FacebookMessagingException(
                FacebookMessagingException::WINDOW,
                __('The 24-hour reply window has closed. The person must write again before the Page can answer.'),
                'window_closed',
            );
        }

        if ($code === 551 || in_array($subcode, self::RECIPIENT_SUBCODES, true)) {
            return new FacebookMessagingException(
                FacebookMessagingException::RECIPIENT,
                __('This person cannot receive messages from the Page right now.'),
                'recipient_unavailable',
            );
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $response->status() === 429) {
            return new FacebookMessagingException(
                FacebookMessagingException::RATE_LIMIT,
                __('Facebook is limiting requests right now. Nothing was sent. Try again in a few minutes.'),
                'rate_limited',
            );
        }

        if (in_array($code, [3, 10], true) || ($code >= 200 && $code <= 299)) {
            return new FacebookMessagingException(
                FacebookMessagingException::PERMISSION,
                __('Facebook says this connection is missing a permission needed for messages. Connect the Page again and approve every permission it asks for.'),
                FacebookGraphErrors::PERMISSION,
            );
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return new FacebookMessagingException(
                FacebookMessagingException::UNAVAILABLE,
                $sending
                    ? __('Facebook had a problem and did not confirm the message. It may have been delivered: check the conversation in Meta Business Suite before sending it again.')
                    : __('Facebook had a problem answering. Try again in a little while.'),
                'platform_error',
                maybeSent: $sending,
            );
        }

        return new FacebookMessagingException(
            FacebookMessagingException::REJECTED,
            FacebookGraphErrors::clean($response->json('error.message'), __('Facebook rejected the request.')),
            'facebook_'.($code > 0 ? $code : $response->status()),
        );
    }

    private function tokenMessage(): string
    {
        return __('Facebook no longer accepts this Page connection. The access was removed or has expired. Connect the Page again to continue.');
    }

    private function client(string $token): PendingRequest
    {
        return $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0')->withToken($token);
    }

    private function url(string $path): string
    {
        $version = config('social.platforms.facebook.api_version');

        return 'https://graph.facebook.com/'.(is_string($version) && $version !== '' ? $version : 'v23.0').'/'.ltrim($path, '/');
    }
}
