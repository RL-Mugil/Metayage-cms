<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * The AI Assistant page: Claude (Anthropic Messages API) or Groq (OpenAI-compatible) answering
 * with the same read-only tools as the Claude MCP connector, as the signed-in user. It never
 * runs SQL and has no tool that writes.
 */
class Assistant
{
    private const MAX_ROUNDS = 6;

    private const MAX_TOOL_RESULT = 24000;

    public function __construct(private ToolRegistry $registry) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{response: string, tools_used: array<int, string>, provider: string}
     */
    public function ask(User $user, string $provider, string $query, array $history = []): array
    {
        ['key' => $key, 'model' => $model] = Keys::for($provider);
        if (! $key) {
            throw new MissingKeyException($provider);
        }
        $history = collect($history)->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true) && trim((string) ($m['content'] ?? '')) !== '')
            ->take(-10)->map(fn ($m) => ['role' => $m['role'], 'content' => mb_substr((string) $m['content'], 0, 4000)])->values()->all();

        return $provider === 'claude'
            ? $this->claude($user, $key, $model, $query, $history)
            : $this->groq($user, $key, $model, $query, $history);
    }

    private function system(User $user): string
    {
        return implode("\n", [
            'You are the AI Assistant of MyIPStrategy (Metayage), an Indian IP law firm portal for patents, trademarks and designs.',
            "You are helping {$user->name} (role: {$user->role}). Today is ".now()->toDateString().'.',
            'Answer only from the portal tools. Never invent cases, numbers or deadlines; if a tool returns nothing, say so.',
            'You have read-only access: you cannot create, edit or delete anything. If asked to change data, explain the user must do it in the portal.',
            'The tools already limit data to what this user may see. Money is INR unless stated. Mention when results are truncated.',
            'Be concise. Use short tables or bullet lists for lists of cases, deadlines or invoices. Give docket numbers.',
        ]);
    }

    private function toolResult(User $user, string $name, array $args, string $source): string
    {
        $json = json_encode($this->registry->call($user, $name, $args, $source), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_strlen($json) > self::MAX_TOOL_RESULT ? mb_substr($json, 0, self::MAX_TOOL_RESULT).'… [truncated]' : $json;
    }

    private function claude(User $user, string $key, string $model, string $query, array $history): array
    {
        $tools = array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(), 'description' => $t->description(), 'input_schema' => $t->inputSchema() + ['type' => 'object'],
        ], $this->registry->for($user)));
        $messages = [...$history, ['role' => 'user', 'content' => $query]];
        $used = [];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model, 'max_tokens' => 2048, 'system' => $this->system($user), 'tools' => $tools, 'messages' => $messages,
                ]);
            if ($res->failed()) {
                throw new \RuntimeException('Claude API error '.$res->status().': '.mb_substr($res->body(), 0, 500));
            }
            $content = (array) $res->json('content', []);
            $calls = array_values(array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            if ($res->json('stop_reason') !== 'tool_use' || $calls === []) {
                return ['response' => $this->text($content), 'tools_used' => array_values(array_unique($used)), 'provider' => 'claude'];
            }
            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($calls as $call) {
                $used[] = $call['name'];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'],
                    'content' => $this->toolResult($user, (string) $call['name'], (array) ($call['input'] ?? []), 'assistant-claude')];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['response' => 'I needed too many steps for that question. Please ask something narrower.', 'tools_used' => array_values(array_unique($used)), 'provider' => 'claude'];
    }

    private function text(array $content): string
    {
        $text = trim(implode("\n", array_map(fn ($b) => (string) ($b['text'] ?? ''), array_filter($content, fn ($b) => ($b['type'] ?? '') === 'text'))));

        return $text !== '' ? $text : 'No answer was returned.';
    }

    private function groq(User $user, string $key, string $model, string $query, array $history): array
    {
        $tools = array_values(array_map(fn (Tool $t) => ['type' => 'function', 'function' => [
            'name' => $t->name(), 'description' => $t->description(), 'parameters' => $t->inputSchema() + ['type' => 'object'],
        ]], $this->registry->for($user)));
        $messages = [['role' => 'system', 'content' => $this->system($user)], ...$history, ['role' => 'user', 'content' => $query]];
        $used = [];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $res = Http::withToken($key)->timeout(60)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model, 'max_tokens' => 2048, 'temperature' => 0.2, 'messages' => $messages, 'tools' => $tools, 'tool_choice' => 'auto',
            ]);
            if ($res->failed()) {
                throw new \RuntimeException('Groq API error '.$res->status().': '.mb_substr($res->body(), 0, 500));
            }
            $message = (array) $res->json('choices.0.message', []);
            $calls = (array) ($message['tool_calls'] ?? []);
            if ($calls === []) {
                $text = trim((string) ($message['content'] ?? ''));

                return ['response' => $text !== '' ? $text : 'No answer was returned.', 'tools_used' => array_values(array_unique($used)), 'provider' => 'groq'];
            }
            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $used[] = $name;
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => $this->toolResult($user, $name, is_array($args) ? $args : [], 'assistant-groq')];
            }
        }

        return ['response' => 'I needed too many steps for that question. Please ask something narrower.', 'tools_used' => array_values(array_unique($used)), 'provider' => 'groq'];
    }
}
