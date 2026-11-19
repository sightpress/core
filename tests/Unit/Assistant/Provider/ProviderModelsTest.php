<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Provider;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Provider\ProviderModels;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(ProviderModels::class)]
final class ProviderModelsTest extends TestCase
{
    public function testReadsTheMaintainedList(): void
    {
        $models = new ProviderModels();

        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-2'], $models->names('anthropic'));
        self::assertSame([], $models->names('openai_compatible'));
        self::assertSame('Mistral Large', $models->label('mistral', 'mistral-large-latest'));
        self::assertSame('custom-model', $models->label('mistral', 'custom-model'));
        self::assertTrue($models->vision('openai', 'gpt-5'));
        self::assertFalse($models->vision('openai', 'gpt-unknown'));
    }

    public function testRefusesAListItCannotRead(): void
    {
        $directory = new TemporaryDirectory();
        $cases = [
            'missing.php' => null,
            'text.php' => '<?php return "models";',
            'flat.php' => '<?php return ["anthropic" => "claude"];',
            'entry.php' => '<?php return ["anthropic" => ["claude" => ["Claude"]]];',
        ];
        try {
            foreach ($cases as $file => $content) {
                if ($content !== null) {
                    $directory->write($file, $content);
                }
                try {
                    new ProviderModels($directory->path . '/' . $file)->names('anthropic');
                    self::fail('Expected ' . $file . ' to be refused.');
                } catch (LogicException $exception) {
                    self::assertStringContainsString($file, $exception->getMessage());
                }
            }
        } finally {
            $directory->remove();
        }
    }
}
