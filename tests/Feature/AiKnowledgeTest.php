<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Zofe\Ai\Services\AiKnowledge;
use Zofe\Ai\Tests\TestCase;

class AiKnowledgeTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ai-knowledge-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/20-cases.md', "# Cases\n\nThe second document.");
        file_put_contents($this->dir . '/10-product.md', "# Product\n\nThe first document.");
        file_put_contents($this->dir . '/_README.md', "How to write these files.");
        file_put_contents($this->dir . '/notes.txt', "Not markdown.");
        config(['ai.widget.knowledge_max' => 16000]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_directory_is_every_markdown_file_in_it_in_name_order()
    {
        config(['ai.widget.knowledge' => $this->dir]);

        $knowledge = app(AiKnowledge::class);

        $this->assertSame(
            [$this->dir . '/10-product.md', $this->dir . '/20-cases.md'],
            $knowledge->sources(),
            'name order; _README.md and notes.txt left out'
        );
        $this->assertSame("# Product\n\nThe first document.\n\n---\n\n# Cases\n\nThe second document.", $knowledge->text());
    }

    public function test_a_list_mixes_files_directories_and_urls()
    {
        $extra = tempnam(sys_get_temp_dir(), 'knowledge');
        file_put_contents($extra, "# Extra");
        config(['ai.widget.knowledge' => [$extra, $this->dir, 'https://example.test/kb.md']]);

        $sources = app(AiKnowledge::class)->sources();

        $this->assertSame([$extra, $this->dir . '/10-product.md', $this->dir . '/20-cases.md', 'https://example.test/kb.md'], $sources);
        unlink($extra);
    }

    public function test_a_single_file_still_works_as_before()
    {
        config(['ai.widget.knowledge' => $this->dir . '/10-product.md']);

        $this->assertSame("# Product\n\nThe first document.", app(AiKnowledge::class)->text());
    }

    public function test_trimming_to_knowledge_max_is_said_in_the_log()
    {
        config(['ai.widget.knowledge' => $this->dir, 'ai.widget.knowledge_max' => 20]);
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'trimmed'));

        $this->assertSame(20, mb_strlen(app(AiKnowledge::class)->text()));
    }
}
