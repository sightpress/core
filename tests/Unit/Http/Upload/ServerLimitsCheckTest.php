<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Core\Template\Message;
use SightPress\Core\Tests\Support\ServerLimitsFixture;

#[CoversClass(ServerLimitsCheck::class)]
#[CoversClass(UploadAllowance::class)]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Media')]
final class ServerLimitsCheckTest extends TestCase
{
    private const int MB = ServerLimitsFixture::MB;

    public function testTheRecommendedValuesHaveNoProblems(): void
    {
        $check = $this->check(ServerLimitsFixture::standard());

        self::assertSame([], $check->problems());
        self::assertSame([
            'The site accepts audio files up to 200 MB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
            'The site accepts videos up to 1 GB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
        ], self::texts($check->notes()));
    }

    public function testPhpsOwnDefaultsAreExplainedWithTheSettingsToChange(): void
    {
        self::assertSame([
            'Files larger than 2 MB can’t be uploaded because of your server’s settings, although the site accepts pictures up to 20 MB and documents up to 50 MB. Ask your hosting provider to raise upload_max_filesize to 50M and post_max_size to 51M.',
            'The server setting memory_limit (128 MB) is too low to resize pictures of up to 25 million pixels. Raise it to at least 256M.',
            'The server setting max_input_vars is 1000, so saving a long page in the block editor can lose some of its content. Raise it to at least 5000.',
        ], self::texts($this->check(ServerLimitsFixture::phpDefaults())->problems()));
        self::assertContains(
            'The web server’s request body limit isn’t stated (uploads.proxy_body_limit in config/tenant.php), so it couldn’t be checked.',
            self::texts($this->check(ServerLimitsFixture::phpDefaults())->notes()),
        );
    }

    public function testNamesTheWebServerWhenItIsTheLimit(): void
    {
        self::assertSame([
            'Files larger than 31 MB can’t be uploaded because of your server’s settings, although the site accepts pictures up to 20 MB and documents up to 50 MB. Ask your hosting provider to raise the web server’s limit (for nginx, client_max_body_size) to 51M.',
            'The web server accepts at most 32 MB per request, less than PHP’s upload_max_filesize (64 MB) allows. Raise the web server’s limit (for nginx, client_max_body_size) to at least 65M, and set uploads.proxy_body_limit in config/tenant.php to match.',
        ], self::texts($this->check(ServerLimitsFixture::standard(proxy: 32 * self::MB))->problems()));
    }

    public function testAPostLimitBelowTheFileLimitIsAProblem(): void
    {
        self::assertSame([
            'Files larger than 31 MB can’t be uploaded because of your server’s settings, although the site accepts pictures up to 20 MB and documents up to 50 MB. Ask your hosting provider to raise post_max_size to 51M.',
            'The server setting post_max_size (32 MB) is smaller than upload_max_filesize (64 MB), so large files fail with a confusing error. Set post_max_size to at least 65M.',
        ], self::texts($this->check(ServerLimitsFixture::standard(post: 32 * self::MB, proxy: null))->problems()));
    }

    public function testTheFolderForUploadsMustBeWritableAndHaveRoom(): void
    {
        self::assertSame(
            ['The folder for files being uploaded (/tmp) is missing or PHP can’t write to it, so no file can be uploaded. Set upload_tmp_dir to a folder PHP can write to.'],
            self::texts($this->check(ServerLimitsFixture::standard(tempWritable: false, tempFree: null))->problems()),
        );
        self::assertSame(
            ['The folder for files being uploaded (/tmp) has only 10 MB free, so large files can fail. Free some space, or set upload_tmp_dir to a folder with more room.'],
            self::texts($this->check(ServerLimitsFixture::standard(tempFree: 10 * self::MB))->problems()),
        );
        self::assertSame([], $this->check(ServerLimitsFixture::standard(tempFree: null))->problems(), 'unknown free space');
    }

    public function testNoLimitsMeansNothingToRaise(): void
    {
        $check = $this->check(ServerLimitsFixture::standard(upload: null, post: null, memory: null, proxy: null));

        self::assertSame([], $check->problems());
        self::assertSame(['The web server’s request body limit isn’t stated (uploads.proxy_body_limit in config/tenant.php), so it couldn’t be checked.'], self::texts($check->notes()));
    }

    public function testTheSitesOwnLimitsCount(): void
    {
        $check = new ServerLimitsCheck(new MediaLimits(imageBytes: self::MB, documentBytes: self::MB, imagePixels: 50_000_000), ServerLimitsFixture::phpDefaults());

        self::assertSame([
            'The server setting memory_limit (128 MB) is too low to resize pictures of up to 50 million pixels. Raise it to at least 512M.',
            'The server setting max_input_vars is 1000, so saving a long page in the block editor can lose some of its content. Raise it to at least 5000.',
        ], self::texts($check->problems()));
    }

    public function testTheAllowanceIsTheSitesLimitWithinTheServers(): void
    {
        $allowance = new UploadAllowance(new MediaLimits(), ServerLimitsFixture::standard());

        self::assertSame(['image' => 20 * self::MB, 'video' => 64 * self::MB, 'audio' => 64 * self::MB, '*' => 50 * self::MB], $allowance->forBrowser());
        self::assertSame('Largest file you can upload: pictures 20 MB, documents 50 MB, audio files 64 MB and videos 64 MB.', (string) $allowance->summary());
        self::assertSame(['image' => 20 * self::MB, 'video' => 100 * self::MB, 'audio' => 100 * self::MB, '*' => 50 * self::MB], $allowance->forChunkedBrowser(100 * self::MB));
        self::assertSame('Largest file you can upload: pictures 20 MB, documents 50 MB, audio files 100 MB and videos 100 MB.', (string) $allowance->chunkedSummary(100 * self::MB));
    }

    private function check(ServerLimits $server): ServerLimitsCheck
    {
        return new ServerLimitsCheck(new MediaLimits(), $server);
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    private static function texts(array $messages): array
    {
        return array_map(static fn (Message $message): string => (string) $message, $messages);
    }
}
