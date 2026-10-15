<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use Illuminate\Http\Client\Response;

/**
 * `POST /{page-id}/subscribed_apps` with `subscribed_fields` SETS the list of Page webhook fields this app receives:
 * a call naming only `feed` drops `leadgen` and `messages`. Every feature that subscribes (audience comments, lead ads,
 * direct messages) therefore reads the current list first (`GET /{page-id}/subscribed_apps`, our app's entry) and
 * sends the union, and removes only its own fields when it is switched off.
 *
 * Docs: https://developers.facebook.com/docs/graph-api/reference/page/subscribed_apps/
 * Written against the documented API; NOT exercised against Meta's live API in this repository.
 */
final class FacebookPageSubscriptions
{
    /**
     * The fields our app is subscribed to, from a `GET /{page-id}/subscribed_apps` answer. An answer without our app
     * (or without a list) is no subscription.
     *
     * @return list<string>
     */
    public static function fieldsOf(Response $response): array
    {
        $appId = config('social.platforms.facebook.client_id');

        foreach ((array) $response->json('data', []) as $app) {
            if (! is_array($app) || ! is_array($app['subscribed_fields'] ?? null)) {
                continue;
            }

            $id = $app['id'] ?? null;

            if ((is_string($id) || is_int($id)) && is_string($appId) && $appId !== '' && (string) $id !== $appId) {
                continue;
            }

            return self::clean($app['subscribed_fields']);
        }

        return [];
    }

    /**
     * The list to send: what is subscribed now, plus `$add`, minus `$remove`. Order is stable and duplicates are gone.
     *
     * @param  list<string>  $current
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>
     */
    public static function merge(array $current, array $add, array $remove = []): array
    {
        $fields = array_values(array_unique([...self::clean($current), ...self::clean($add)]));

        return array_values(array_diff($fields, self::clean($remove)));
    }

    /**
     * @param  array<mixed>  $fields
     * @return list<string>
     */
    private static function clean(array $fields): array
    {
        $clean = [];

        foreach ($fields as $field) {
            // A field may come back as a name or as `{"name": ...}`.
            $name = is_array($field) ? ($field['name'] ?? null) : $field;

            if (is_string($name) && preg_match('/^[a-z_]{1,64}$/', $name) === 1) {
                $clean[] = $name;
            }
        }

        return array_values(array_unique($clean));
    }
}
