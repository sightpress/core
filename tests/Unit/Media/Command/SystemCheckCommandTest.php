<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Media\Command\SystemCheckCommand;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Module\Console\Command;

#[CoversClass(SystemCheckCommand::class)]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesNamespace('SightPress\Core\Media')]
#[UsesNamespace('SightPress\Core\Template')]
final class SystemCheckCommandTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $command = $this->command(ServerLimitsFixture::standard());

        self::assertSame('system:check', $command->name());
        self::assertStringStartsWith('Checks the server\'s upload and input limits', $command->description());
        self::assertSame([], $command->parameters());
    }

    public function testTheRecommendedValuesPass(): void
    {
        $output = new RecordingOutput();

        self::assertSame(Command::SUCCESS, $this->command(ServerLimitsFixture::standard())->run(new ArrayCommandInput(), $output));
        self::assertSame([
            'Upload limits',
            '  Largest file in one request: 64 MB',
            '    upload_max_filesize 64 MB, post_max_size 65 MB, web server 65 MB',
            '  Pictures: 20 MB',
            '  Videos: 64 MB (the site allows 1 GB)',
            '  Audio files: 64 MB (the site allows 200 MB)',
            '  Documents: 50 MB',
            '  memory_limit 256 MB, max_input_vars 5000, max_file_uploads 20',
            '  Folder for files being uploaded: /tmp (10 GB free)',
            'Note: The site accepts audio files up to 200 MB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
            'Note: The site accepts videos up to 1 GB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
            'No problems found.',
        ], $output->lines);
        self::assertSame([], $output->errors);
    }

    public function testProblemsFailTheCheck(): void
    {
        $output = new RecordingOutput();

        self::assertSame(Command::FAILURE, $this->command(ServerLimitsFixture::phpDefaults())->run(new ArrayCommandInput(), $output));
        self::assertContains('    upload_max_filesize 2 MB, post_max_size 8 MB, web server not stated', $output->lines);
        self::assertCount(3, $output->errors);
        self::assertStringStartsWith('Files larger than 2 MB can’t be uploaded', $output->errors[0]);
        self::assertNotContains('No problems found.', $output->lines);
    }

    public function testUnlimitedAndUnknownValuesAreSaidInWords(): void
    {
        $output = new RecordingOutput();
        $this->command(ServerLimitsFixture::standard(upload: null, post: null, memory: null, proxy: null, tempFree: null))->run(new ArrayCommandInput(), $output);

        self::assertContains('  Largest file in one request: no limit', $output->lines);
        self::assertContains('  memory_limit no limit, max_input_vars 5000, max_file_uploads 20', $output->lines);
        self::assertContains('  Folder for files being uploaded: /tmp (free space unknown)', $output->lines);

        $output = new RecordingOutput();
        $this->command(ServerLimitsFixture::standard(tempWritable: false, tempFree: null))->run(new ArrayCommandInput(), $output);
        self::assertContains('  Folder for files being uploaded: /tmp (missing or not writable)', $output->lines);
    }

    private function command(ServerLimits $server): SystemCheckCommand
    {
        return new SystemCheckCommand($server, new MediaLimits(), new ServerLimitsCheck(new MediaLimits(), $server));
    }
}
