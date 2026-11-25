<?php

namespace App\Ai;

use App\Models\Integration;

/**
 * API keys for the AI Assistant providers. A System Admin enters them on the Integrations page
 * (stored encrypted, never sent to the browser); a Groq key in .env still works as a fallback.
 */
class Keys
{
    public const PROVIDERS = ['claude' => 'anthropic', 'groq' => 'groq'];

    public const DEFAULT_MODELS = ['claude' => 'claude-haiku-5-5', 'groq' => 'meta-llama/llama-4-scout-17b-16e-instruct'];

    /** @return array{key: ?string, model: string} */
    public static function for(string $provider): array
    {
        $slug = self::PROVIDERS[$provider] ?? null;
        if (! $slug) {
            return ['key' => null, 'model' => ''];
        }
        $row = Integration::where('slug', $slug)->first();
        $config = $row?->config ?? [];
        $key = null;
        if ($row && $row->connected && ! empty($config['api_key'])) {
            try {
                $key = decrypt($config['api_key']);
            } catch (\Throwable) {
                $key = null;
            }
        }
        if (! $key && $provider === 'groq') {
            $key = config('services.groq.api_key') ?: null;
        }
        $model = trim((string) ($config['model'] ?? '')) ?: ($provider === 'groq' ? (string) config('services.groq.model', self::DEFAULT_MODELS['groq']) : self::DEFAULT_MODELS['claude']);

        return ['key' => $key ?: null, 'model' => $model];
    }

    public static function available(string $provider): bool
    {
        return self::for($provider)['key'] !== null;
    }

    /** The Claude connector (MCP) switch on the Integrations page; on unless turned off. */
    public static function mcpEnabled(): bool
    {
        $row = Integration::where('slug', 'claude_mcp')->first();

        return $row === null || (bool) $row->connected;
    }
}
