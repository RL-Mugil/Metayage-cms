<?php

namespace App\Jobs;

use App\Models\MobileDeviceToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendMobilePushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    /** Exponential backoff between retries (seconds). */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @param array<int> $userIds
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly array $userIds,
        private readonly string $title,
        private readonly string $body,
        private readonly ?string $actionUrl,
        private readonly string $type,
        private readonly array $meta = [],
    ) {}

    public function handle(): void
    {
        $tokens = MobileDeviceToken::query()
            ->whereIn('user_id', $this->userIds)
            ->get(['id', 'push_token']);

        if ($tokens->isEmpty()) {
            return;
        }

        $rows = $tokens->map(fn (MobileDeviceToken $token) => [
            'to' => $token->push_token,
            'title' => $this->title,
            'body' => $this->body,
            'sound' => 'default',
            'data' => [
                'type' => $this->type,
                'action_url' => $this->actionUrl,
                'meta' => $this->meta,
            ],
        ])->values();

        foreach ($rows->chunk(100) as $chunk) {
            $response = Http::timeout(8)
                ->withHeaders(array_filter([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => config('services.expo.access_token')
                        ? 'Bearer ' . config('services.expo.access_token')
                        : null,
                ]))
                ->post('https://exp.host/--/api/v2/push/send', $chunk->all());

            if (! $response->ok()) {
                report(new \RuntimeException('Expo push request failed: ' . $response->body()));
                continue;
            }

            $data = $response->json('data');
            if (! is_array($data)) {
                continue;
            }

            foreach ($data as $index => $result) {
                $detailsError = $result['details']['error'] ?? null;
                if (($result['status'] ?? null) === 'error' && $detailsError === 'DeviceNotRegistered') {
                    $message = $chunk->get($index);
                    if (! $message) {
                        continue;
                    }

                    MobileDeviceToken::query()
                        ->where('push_token', $message['to'])
                        ->delete();
                }
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendMobilePushNotificationJob failed for user_ids [' . implode(',', $this->userIds) . "]: {$e->getMessage()}");
    }
}
