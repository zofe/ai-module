<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\Services\AiRedactionException;
use Zofe\Ai\Services\AiRedactor;
use Zofe\Ai\Services\AiService;
use Zofe\Ai\Tests\TestCase;
use Zofe\Rapyd\Contracts\AiTool;
use Zofe\Rapyd\Contracts\AiToolProvider;

/** The names the application knows, for the tests. */
class KnownNames
{
    public function __invoke(): array
    {
        return ['Pasticceria Rossi srl' => 'company', 'Rossi', 'Box di Bari' => 'device'];
    }
}

class AiRedactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.redact.enabled' => true, 'ai.redact.presidio.url' => null, 'ai.redact.terms' => null, 'ai.redact.keep' => []]);
    }

    public function test_the_values_with_a_shape_become_placeholders_and_come_back()
    {
        $r = new AiRedactor;
        $text = 'IP 79.10.1.2 e di nuovo 79.10.1.2, mario@esempio.it, cell 333 1234567, MAC c8:77:65:20:63:5d, IBAN IT60X0542811101000000123456.';
        $masked = $r->mask($text);

        foreach (['79.10.1.2', 'mario@esempio.it', '333 1234567', 'c8:77:65:20:63:5d', 'IT60X0542811101000000123456'] as $value) {
            $this->assertStringNotContainsString($value, $masked);
        }
        $this->assertSame(2, substr_count($masked, '[IP_ADDRESS_1]'), 'the same value, the same placeholder');
        $this->assertSame($text, $r->unmask($masked));
    }

    public function test_ipv6_also_abbreviated_but_not_a_time()
    {
        $r = new AiRedactor;

        $masked = $r->mask('VPS 2a01:4f8:c0c:1234::1, link fe80::1, lungo 2001:db8:0:0:0:0:0:1; alle 10:30:15');

        $this->assertMatchesRegularExpression('/^VPS \[IPV6_ADDRESS_\d\], link \[IPV6_ADDRESS_\d\], lungo \[IPV6_ADDRESS_\d\]; alle 10:30:15$/', $masked);
    }

    public function test_the_names_the_application_knows_the_longest_first()
    {
        config(['ai.redact.terms' => KnownNames::class]);
        $r = new AiRedactor;
        $masked = $r->mask('La pasticceria rossi SRL ha chiamato; il signor Rossi dice che il Box di Bari è giù.');

        $this->assertSame('La [COMPANY_1] ha chiamato; il signor [NAME_1] dice che il [DEVICE_1] è giù.', $masked);
    }

    public function test_the_jargon_is_never_masked()
    {
        config(['ai.redact.terms' => KnownNames::class, 'ai.redact.keep' => ['rossi']]);

        $this->assertSame('il signor Rossi', (new AiRedactor)->mask('il signor Rossi'));
    }

    public function test_presidio_finds_the_free_text_and_the_surest_wins_where_two_overlap()
    {
        config(['ai.redact.presidio.url' => 'https://presidio.test']);
        $text = 'Città: Bari, chiamare Mario Rossi, mario@esempio.it';
        $at = fn (string $what) => mb_strpos($text, $what);
        Http::fake(['presidio.test/analyze' => Http::response([
            ['entity_type' => 'LOCATION', 'start' => $at('Bari'), 'end' => $at('Bari') + 4, 'score' => 0.85],
            ['entity_type' => 'PERSON', 'start' => $at('Mario Rossi'), 'end' => $at('Mario Rossi') + 11, 'score' => 0.85],
            // Presidio sees a person in the e-mail too: the e-mail pattern is surer.
            ['entity_type' => 'PERSON', 'start' => $at('mario@'), 'end' => $at('mario@') + 5, 'score' => 0.85],
            ['entity_type' => 'DATE_TIME', 'start' => 0, 'end' => 5, 'score' => 0.9],
        ])]);

        $masked = (new AiRedactor)->mask($text);

        // Offsets in characters: "Città" has a two-byte letter before the first match.
        $this->assertSame('Città: [LOCATION_1], chiamare [PERSON_1], [EMAIL_ADDRESS_1]', $masked);
        Http::assertSent(fn (Request $r) => $r['language'] === 'it' && $r['text'] === $text);
    }

    public function test_presidio_does_not_read_the_system_prompt()
    {
        config(['ai.redact.presidio.url' => 'https://presidio.test', 'ai.widget.system_prompt' => 'Il Canale del Box. Scrivi a info@esempio.it']);
        Http::fake([
            'presidio.test/analyze' => Http::response([]),
            'api.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        app(AiService::class)->chat([['role' => 'user', 'content' => 'Mario Rossi']], false);

        // Presidio sees the message only, once; the patterns still mask the system prompt.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'presidio.test') && $r['text'] === 'Mario Rossi');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.test')
            && str_contains(json_encode($r->data()), 'Il Canale del Box') && ! str_contains(json_encode($r->data()), 'info@esempio.it'));
    }

    public function test_when_presidio_does_not_answer_nothing_leaves()
    {
        config(['ai.redact.presidio.url' => 'https://presidio.test']);
        Http::fake(['presidio.test/*' => Http::response('down', 503), 'api.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        try {
            app(AiService::class)->chat([['role' => 'user', 'content' => 'Mario Rossi']], false);
            $this->fail('the call should have been blocked');
        } catch (AiRedactionException) {
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.test'));

        config(['ai.redact.on_error' => 'pass']);
        $this->assertSame('ok', app(AiService::class)->chat([['role' => 'user', 'content' => 'Mario Rossi']], false));
    }

    public function test_the_provider_sees_placeholders_the_tool_and_the_user_the_real_values()
    {
        config(['ai.redact.terms' => KnownNames::class]);
        $asked = null;
        AiRegistry::register(new class($asked) implements AiToolProvider
        {
            public function __construct(public &$asked) {}

            public function tools(): array
            {
                return [new AiTool('customer_lines', 'The lines of a customer', ['type' => 'object'], function (array $input) {
                    $this->asked = $input['customer'] ?? null;

                    return 'Pasticceria Rossi srl: linea su 79.10.1.2, giù da ieri';
                })];
            }
        });
        Http::fakeSequence('api.test/*')
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 't1', 'type' => 'function', 'function' => ['name' => 'customer_lines', 'arguments' => '{"customer":"[COMPANY_1]"}']],
            ]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'La linea di [COMPANY_1] su [IP_ADDRESS_1] è giù da ieri.']]]]);

        $service = app(AiService::class);
        $reply = $service->chat([['role' => 'user', 'content' => 'Come va la Pasticceria Rossi srl?']]);

        $this->assertSame('Pasticceria Rossi srl', $asked, 'the tool works on the real name');
        $this->assertSame('La linea di Pasticceria Rossi srl su 79.10.1.2 è giù da ieri.', $reply, 'the user reads the real values');
        Http::assertSent(function (Request $r) {
            $body = json_encode($r->data());

            return str_contains($r->url(), 'api.test')
                && ! str_contains($body, 'Rossi') && ! str_contains($body, '79.10.1.2')
                && str_contains($body, 'placeholders');
        });
        $this->assertGreaterThan(0, $service->lastMasked, 'the call says how many values it masked');
    }
}
