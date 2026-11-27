<?php

namespace App\Services;

use App\Models\Client;
use App\Support\Notifier;

/**
 * Notifications to a client's own portal users (the bell, live update and mobile push): a quote
 * sent, an invoice raised, a payment received, an approval waiting or decided, a deadline or
 * renewal coming up, a reply in their discussion. Never throws: a notice must not break the
 * business action that caused it.
 */
class ClientNotifier
{
    public static function push(?int $clientId, string $type, string $title, string $message, string $url, array $data = [], array $exceptUserIds = []): void
    {
        if (! $clientId) {
            return;
        }
        rescue(function () use ($clientId, $type, $title, $message, $url, $data, $exceptUserIds) {
            $client = Client::find($clientId);
            $ids = $client ? array_values(array_diff($client->portalUserIds(), $exceptUserIds)) : [];
            if ($ids !== []) {
                Notifier::push($ids, $type, $title, $message, $url, $data + ['client_id' => $clientId]);
            }
        }, null, true);
    }
}
