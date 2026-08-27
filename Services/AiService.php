<?php

namespace Zofe\Ai\Services;

use Illuminate\Support\Facades\Http;
use Zofe\Ai\AiRegistry;

class AiService
{
    protected string $provider;

    public function __construct()
    {
        $this->provider = config('ai.provider', 'anthropic');
    }

    /**
     * Send a user message (with optional tool context) and return the text reply.
     * Handles one tool-use round-trip automatically.
     *
     * @param  array  $messages  [['role' => 'user'|'assistant', 'content' => '...']]
     * @param  bool   $withTools  Include registered AiTool definitions in the request
     */
    public function chat(array $messages, bool $withTools = true): string
    {
        return match ($this->provider) {
            'openai'  => $this->chatOpenAi($messages, $withTools),
            'ollama'  => $this->chatOllama($messages, $withTools),
            default   => $this->chatAnthropic($messages, $withTools),
        };
    }

    // -------------------------------------------------------------------------

    protected function chatAnthropic(array $messages, bool $withTools): string
    {
        $payload = [
            'model'      => config('ai.anthropic.model'),
            'max_tokens' => config('ai.widget.max_tokens', 1024),
            'system'     => $this->systemPrompt(),
            'messages'   => $messages,
        ];

        if ($withTools && count(AiRegistry::tools()) > 0) {
            $payload['tools'] = AiRegistry::definitions();
        }

        $response = Http::withHeaders([
            'x-api-key'         => config('ai.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', $payload);

        $response->throw();
        $content = $response->json('content', []);

        // Handle tool_use block: execute the tool and send result back
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && AiRegistry::has($block['name'])) {
                $toolResult = AiRegistry::execute($block['name'], $block['input'] ?? []);

                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' => [[
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => is_string($toolResult) ? $toolResult : json_encode($toolResult),
                ]]];

                // Second call with tool result — no tools this round to avoid loops
                return $this->chatAnthropic($messages, false);
            }
        }

        // Return the first text block
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'];
            }
        }

        return '';
    }

    protected function chatOpenAi(array $messages, bool $withTools): string
    {
        $payload = [
            'model'      => config('ai.openai.model'),
            'max_tokens' => config('ai.widget.max_tokens', 1024),
            'messages'   => array_merge(
                [['role' => 'system', 'content' => $this->systemPrompt()]],
                $messages
            ),
        ];

        if ($withTools && count(AiRegistry::tools()) > 0) {
            $payload['tools'] = array_map(fn ($def) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'parameters'  => $def['input_schema'],
                ],
            ], AiRegistry::definitions());
        }

        $response = Http::withToken(config('ai.openai.key'))
            ->post(config('ai.openai.base_url') . '/chat/completions', $payload);

        $response->throw();
        $choice  = $response->json('choices.0');
        $message = $choice['message'] ?? [];

        // Handle tool calls — execute ALL calls, one tool result per call
        if (!empty($message['tool_calls'])) {
            $messages[] = $message;

            foreach ($message['tool_calls'] as $call) {
                $toolName   = $call['function']['name'];
                $toolInput  = json_decode($call['function']['arguments'], true) ?? [];
                $toolResult = AiRegistry::has($toolName) ? AiRegistry::execute($toolName, $toolInput) : 'Tool not found.';

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'],
                    'content'      => is_string($toolResult) ? $toolResult : json_encode($toolResult),
                ];
            }

            return $this->chatOpenAi($messages, false);
        }

        return $message['content'] ?? '';
    }

    protected function chatOllama(array $messages, bool $withTools): string
    {
        // Ollama's tool-use support varies by model; fall back to context injection
        $systemWithContext = $this->systemPrompt();

        if ($withTools && count(AiRegistry::tools()) > 0) {
            $toolDescriptions = collect(AiRegistry::tools())
                ->map(fn ($t) => "- {$t->name}: {$t->description}")
                ->join("\n");
            $systemWithContext .= "\n\nAvailable tools (answer from your knowledge or call them by name):\n{$toolDescriptions}";
        }

        $response = Http::post(config('ai.ollama.base_url') . '/api/chat', [
            'model'    => config('ai.ollama.model'),
            'stream'   => false,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemWithContext]],
                $messages
            ),
        ]);

        $response->throw();

        return $response->json('message.content', '');
    }

    protected function systemPrompt(): string
    {
        $custom = config('ai.widget.system_prompt');
        if ($custom) {
            return $custom;
        }

        // Auto-generate from rpd:context if available
        try {
            $context = \Artisan::call('rpd:context', ['--no-routes' => true]);
            $json    = \Artisan::output();
            return "You are an AI assistant integrated in a rapyd-admin application. Use the registered tools to answer questions about the application's data. Here is the project context:\n\n{$json}";
        } catch (\Throwable) {
            return 'You are an AI assistant integrated in a rapyd-admin application. Use the registered tools to answer questions about the application data.';
        }
    }
}
