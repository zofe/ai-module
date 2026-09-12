<?php

namespace Zofe\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The markdown the assistant knows the product from: a local file (path
 * relative to the app root, or absolute) or an http(s) URL, set in
 * `ai.widget.knowledge`. Trimmed to `ai.widget.knowledge_max` characters.
 */
class AiKnowledge
{
    public function text(): string
    {
        $source = (string) config('ai.widget.knowledge', '');
        if ($source === '') {
            return '';
        }

        $text = str_starts_with($source, 'http://') || str_starts_with($source, 'https://')
            ? $this->fromUrl($source)
            : $this->fromFile($source);

        $max = (int) config('ai.widget.knowledge_max', 16000);
        if ($max > 0 && mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        return trim($text);
    }

    protected function fromFile(string $path): string
    {
        $file = str_starts_with($path, '/') ? $path : base_path($path);
        if (!is_readable($file)) {
            Log::warning('ai-widget: knowledge file not readable', ['file' => $file]);
            return '';
        }

        return (string) file_get_contents($file);
    }

    protected function fromUrl(string $url): string
    {
        return (string) Cache::remember('ai-knowledge:' . md5($url), 3600, function () use ($url) {
            try {
                return Http::timeout(5)->get($url)->throw()->body();
            } catch (\Throwable $e) {
                Log::warning('ai-widget: knowledge url unreachable', ['url' => $url, 'error' => $e->getMessage()]);
                return '';
            }
        });
    }
}
