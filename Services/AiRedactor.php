<?php

namespace Zofe\Ai\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Masks personal data before a text leaves for the AI provider, and puts it back in what comes
 * back. Every value becomes a placeholder that stays the same for the whole call ("[PERSON_1]",
 * "[IP_ADDRESS_2]"), so the model can still tell that two mentions are the same thing; the tool
 * arguments and the final answer get the real values again. The provider never sees them.
 *
 * Three sources, added together (config `ai.redact`):
 * - `terms`: the names the application already knows (companies, customers, devices), from an
 *   invokable class: exact, case-insensitive, no guessing;
 * - `patterns`: regular expressions for what has a shape (IP and MAC addresses, e-mails, IBANs,
 *   phone numbers);
 * - `presidio`: a Microsoft Presidio analyzer, for the names and places of free text.
 * `keep` lists the words never masked: the jargon of the application ("FWA", "TIM").
 *
 * One instance per conversation call: the placeholders are numbered from 1 each time.
 */
class AiRedactor
{
    /** normalized value => placeholder */
    protected array $byValue = [];

    /** placeholder => original value */
    protected array $byPlaceholder = [];

    /** type => last number used */
    protected array $counters = [];

    /** md5(text) => masked text: the same system prompt is sent at every tool round */
    protected array $cache = [];

    protected ?array $terms = null;

    /** How many values were masked in this call. */
    public int $masked = 0;

    public const PLACEHOLDER = '/\[[A-Z][A-Z_]*_\d+\]/';

    public const DEFAULT_PATTERNS = [
        'EMAIL_ADDRESS' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
        'IP_ADDRESS'    => '/(?<![\d.])(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}(?![\d.])/',
        'IPV6_ADDRESS'  => '/(?<![:\w])(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}(?![:\w])/',
        'MAC_ADDRESS'   => '/(?<![\w:\-])(?:[0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}(?![\w:\-])/',
        'IBAN_CODE'     => '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,4})?\b/',
        'PHONE_NUMBER'  => '/(?<![\d.])(?:\+39[ ]?)?(?:3\d{2}|0\d{1,3})[ .\-]?\d{3}[ .\-]?\d{3,5}(?![\d.])/',
    ];

    public static function enabledFor(string $provider): bool
    {
        return (bool) config('ai.redact.enabled', false)
            && in_array($provider, (array) config('ai.redact.providers', ['anthropic', 'openai']), true);
    }

    /** The text with every personal value replaced by its placeholder. */
    public function mask(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }
        $key = md5($text);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $spans = $this->resolve($text, [...$this->termSpans($text), ...$this->patternSpans($text), ...$this->presidioSpans($text)]);
        usort($spans, fn ($a, $b) => $b['start'] <=> $a['start']);
        $masked = $text;
        foreach ($spans as $s) {
            $value = mb_substr($text, $s['start'], $s['end'] - $s['start']);
            $masked = mb_substr($masked, 0, $s['start']).$this->placeholder($s['type'], $value).mb_substr($masked, $s['end']);
        }

        return $this->cache[$key] = $masked;
    }

    /** The text with the placeholders of this call turned back into the real values. */
    public function unmask(?string $text): ?string
    {
        return $text === null || $this->byPlaceholder === [] ? $text : strtr($text, $this->byPlaceholder);
    }

    /** The same, in every string of an array: the arguments of a tool call. */
    public function unmaskArray(array $data): array
    {
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) {
                $v = $this->unmask($v);
            }
        });

        return $data;
    }

    /** The line added to the system prompt, so the model keeps the placeholders as they are. */
    public static function instructions(): string
    {
        return 'Some values in this conversation (names, companies, places, addresses, contacts) are replaced by placeholders such as [PERSON_1] or [IP_ADDRESS_2]. '
            .'Use the placeholders exactly as written, also as arguments of the tools; never guess or invent what they stand for.';
    }

    // -------------------------------------------------------------------------

    protected function placeholder(string $type, string $value): string
    {
        $norm = mb_strtolower(trim($value));
        if (! isset($this->byValue[$norm])) {
            $this->counters[$type] = ($this->counters[$type] ?? 0) + 1;
            $placeholder = '['.$type.'_'.$this->counters[$type].']';
            $this->byValue[$norm] = $placeholder;
            $this->byPlaceholder[$placeholder] = $value;
        }
        $this->masked++;

        return $this->byValue[$norm];
    }

    /** One span per match of a regular expression, in characters (not bytes). */
    protected function regexSpans(string $text, string $regex, string $type, float $score): array
    {
        $spans = [];
        if (@preg_match_all($regex, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$match, $byte]) {
                $start = mb_strlen(substr($text, 0, $byte));
                $spans[] = ['type' => $type, 'start' => $start, 'end' => $start + mb_strlen($match), 'score' => $score];
            }
        }

        return $spans;
    }

    protected function patternSpans(string $text): array
    {
        $spans = [];
        foreach ((array) (config('ai.redact.patterns') ?? self::DEFAULT_PATTERNS) as $type => $regex) {
            array_push($spans, ...$this->regexSpans($text, $regex, $type, 0.9));
        }

        return $spans;
    }

    /** The names the application knows: the longest first, so "Rossi srl" wins over "Rossi". */
    protected function termSpans(string $text): array
    {
        // One alternation per block of terms, not one expression per term: an application can
        // know tens of thousands of companies.
        $types = [];
        foreach ($this->terms() as $term => $type) {
            $types[mb_strtolower($term)] = $type;
        }
        $spans = [];
        foreach (array_chunk(array_keys($this->terms()), 500) as $chunk) {
            $regex = '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn ($t) => preg_quote($t, '/'), $chunk)).')(?![\p{L}\p{N}])/iu';
            foreach ($this->regexSpans($text, $regex, 'NAME', 1.0) as $span) {
                $span['type'] = $types[mb_strtolower(mb_substr($text, $span['start'], $span['end'] - $span['start']))] ?? 'NAME';
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /** term => type, from the class of `ai.redact.terms`, read once per call. */
    protected function terms(): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }
        $this->terms = [];
        $source = config('ai.redact.terms');
        if (! $source) {
            return $this->terms;
        }
        foreach ((array) app($source)() as $key => $value) {
            [$term, $type] = is_int($key) ? [(string) $value, 'NAME'] : [(string) $key, (string) $value];
            $term = trim($term);
            if (mb_strlen($term) >= (int) config('ai.redact.min_term_length', 3)) {
                $this->terms[$term] = strtoupper($type);
            }
        }
        uksort($this->terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $this->terms;
    }

    /** What Presidio finds in the text; when it does not answer, by default nothing leaves. */
    protected function presidioSpans(string $text): array
    {
        $url = rtrim((string) config('ai.redact.presidio.url'), '/');
        if ($url === '') {
            return [];
        }
        try {
            $response = Http::timeout((int) config('ai.redact.presidio.timeout', 10))->post($url.'/analyze', array_filter([
                'text' => $text,
                'language' => config('ai.redact.presidio.language', 'it'),
                'score_threshold' => (float) config('ai.redact.presidio.score', 0.5),
                'entities' => config('ai.redact.presidio.entities'),
            ], fn ($v) => $v !== null));
            $response->throw();
            $found = (array) $response->json();
        } catch (Throwable $e) {
            if (config('ai.redact.on_error', 'block') === 'block') {
                throw new AiRedactionException('The personal data filter (Presidio) did not answer: nothing was sent to the AI provider.', 0, $e);
            }

            return [];
        }

        $wanted = config('ai.redact.presidio.entities');
        $spans = [];
        foreach ($found as $f) {
            if (! isset($f['start'], $f['end'], $f['entity_type'])) {
                continue;
            }
            if ($wanted === null && in_array($f['entity_type'], (array) config('ai.redact.presidio.ignore', ['DATE_TIME', 'URL', 'NRP']), true)) {
                continue;
            }
            $spans[] = ['type' => $f['entity_type'], 'start' => (int) $f['start'], 'end' => (int) $f['end'], 'score' => (float) ($f['score'] ?? 0.5)];
        }

        return $spans;
    }

    /**
     * The spans to replace: never a word of `keep`, never inside a placeholder already in the
     * text, and where two overlap the surest wins (then the longest): an e-mail is not also a person.
     */
    protected function resolve(string $text, array $spans): array
    {
        $keep = array_map('mb_strtolower', array_map('trim', (array) config('ai.redact.keep', [])));
        $taken = $this->regexSpans($text, self::PLACEHOLDER, 'PLACEHOLDER', 1.0);
        usort($spans, fn ($a, $b) => [$b['score'], $b['end'] - $b['start']] <=> [$a['score'], $a['end'] - $a['start']]);
        $out = [];
        foreach ($spans as $s) {
            $value = trim(mb_substr($text, $s['start'], $s['end'] - $s['start']));
            if ($value === '' || in_array(mb_strtolower($value), $keep, true)) {
                continue;
            }
            foreach ($taken as $t) {
                if ($s['start'] < $t['end'] && $t['start'] < $s['end']) {
                    continue 2;
                }
            }
            $taken[] = $s;
            $out[] = $s;
        }

        return $out;
    }
}
