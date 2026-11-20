<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Site\Command\ClearCacheCommand;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(ClearCacheCommand::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
final class ClearCacheCommandTest extends TestCase
{
    public function testEmptiesTheCacheFolder(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $directory->write('cache/themes.php', '<?php return [];');
            $directory->write('cache/twig/ab/cd.php', '<?php');
            $command = new ClearCacheCommand(TenantConfig::fromArray(['cache_dir' => $directory->path . '/cache'], '/srv/app'));
            $output = new RecordingOutput();

            self::assertSame('cache:clear', $command->name());
            self::assertNotSame('', $command->description());
            self::assertSame([], $command->parameters());
            self::assertSame(0, $command->run(new ArrayCommandInput(), $output));
            self::assertSame(['.', '..'], scandir($directory->path . '/cache'));

            $directory->write('cache/one', '1');
            $command->run(new ArrayCommandInput(), $output);
            new ClearCacheCommand(TenantConfig::fromArray(['cache_dir' => $directory->path . '/none'], '/srv/app'))->run(new ArrayCommandInput(), $output);
            self::assertSame([
                'Emptied the cache folder ' . $directory->path . '/cache (2 files).',
                'Emptied the cache folder ' . $directory->path . '/cache (1 file).',
                'Emptied the cache folder ' . $directory->path . '/none (0 files).',
            ], $output->lines);
        } finally {
            $directory->remove();
        }
    }
}
