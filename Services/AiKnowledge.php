<?php

namespace Zofe\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The markdown the assistant knows the product from, set in `ai.widget.knowledge`:
 * a file (path relative to the app root, or absolute), an http(s) URL, a **directory**
 * (every `*.md` in it, in name order, files starting with `_` or `.` left out), or an
 * array of any of those. The pieces are joined with a rule between them and the whole is
 * trimmed to `ai.widget.knowledge_max` characters — with a warning in the log when that
 * happens, because a silently cut document is worse than none.
 */
class AiKnowledge
{
    public function text(): string
    {
        $sources = $this->sources();
        if ($sources === []) {
            return '';
        }

        $pieces = array_filter(array_map(fn ($source) => trim($this->read($source)), $sources));
        $text = implode("\n\n---\n\n", $pieces);

        $max = (int) config('ai.widget.knowledge_max', 16000);
        if ($max > 0 && mb_strlen($text) > $max) {
            Log::warning('ai-widget: knowledge trimmed to knowledge_max', ['length' => mb_strlen($text), 'max' => $max]);
            $text = mb_substr($text, 0, $max);
        }

        return trim($text);
    }

    /**
     * The files that make up the knowledge, in the order they are read: what the "AI
     * overview" page lists.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $configured = config('ai.widget.knowledge', '');
        $configured = is_array($configured) ? $configured : [(string) $configured];

        $sources = [];
        foreach (array_filter(array_map('trim', $configured)) as $source) {
            if ($this->isUrl($source)) {
                $sources[] = $source;

                continue;
            }

            $path = $this->path($source);
            if (is_dir($path)) {
                $files = glob(rtrim($path, '/') . '/*.md') ?: [];
                sort($files, SORT_NATURAL);
                foreach ($files as $file) {
                    $name = basename($file);
                    if ($name[0] !== '_' && $name[0] !== '.') {
                        $sources[] = $file;
                    }
                }
            } else {
                $sources[] = $path;
            }
        }

        return $sources;
    }

    /**
     * The documents one by one, for the people who want to read what the assistant reads:
     * file (relative to the app root when inside it), title (the first heading, else the
     * file name) and the text.
     *
     * @return list<array{file: string, title: string, text: string}>
     */
    public function documents(): array
    {
        $root = rtrim(base_path(), '/') . '/';

        return array_values(array_filter(array_map(function ($source) use ($root) {
            $text = trim($this->read($source));
            if ($text === '') {
                return null;
            }
            preg_match('/^#\s+(.+)$/m', $text, $m);

            return [
                'file' => str_starts_with($source, $root) ? substr($source, strlen($root)) : $source,
                'title' => trim($m[1] ?? basename($source)),
                'text' => $text,
            ];
        }, $this->sources())));
    }

    protected function read(string $source): string
    {
        return $this->isUrl($source) ? $this->fromUrl($source) : $this->fromFile($source);
    }

    protected function isUrl(string $source): bool
    {
        return str_starts_with($source, 'http://') || str_starts_with($source, 'https://');
    }

    protected function path(string $source): string
    {
        return str_starts_with($source, '/') ? $source : base_path($source);
    }

    protected function fromFile(string $file): string
    {
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
