<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding\Http;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\Http\BrandingAdminHandler;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\BrandingFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(BrandingAdminHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingAdminHandlerTest extends TestCase
{
    private AdminFixture $admin;

    private BrandingFixture $branding;

    private Session $session;

    private User $ada;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->branding = new BrandingFixture('Riverside', OwnerType::Organization, ['en-GB', 'pt-PT']);
        $this->session = new Session();
        $this->ada = $this->admin->auth->user('ada@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        $this->branding->remove();
    }

    public function testOnlyPeopleWhoManageSettingsMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->handle('GET', user: $this->admin->auth->user('bea@example.org', 'editor'));
    }

    public function testShowsEachPictureWithItsPreviewAndTexts(): void
    {
        $html = (string) $this->handle('GET')->getBody();

        self::assertStringContainsString('<title>Branding - Admin - Demo</title>', $html);
        self::assertMatchesRegularExpression('#<img src="/branding/wordmark.svg\?v=[0-9a-f]+" alt="The logo now: Riverside" width="\d+" height="\d+">#', $html);
        self::assertMatchesRegularExpression('#<img src="/icon-512.png\?v=[0-9a-f]+" alt="The icon of Riverside now"#', $html);
        self::assertStringContainsString('This is how it is cropped: 1200 × 630 pixels.', $html);
        self::assertStringContainsString('<label for="logo-alt-pt-pt">What it shows, in Portuguese (Portugal) (optional)</label>', $html);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
        self::assertStringNotContainsString('Reset the', $html, 'nothing uploaded yet');
    }

    public function testUploadsDescribesAndResetsAPicture(): void
    {
        $uploaded = $this->handle('POST', ['asset' => 'share_image', 'action' => 'save', 'alt' => ['en-GB' => 'The river', 'pt-PT' => 'O rio']], $this->file(MediaFixture::jpeg(2400, 1260)));
        self::assertSame('/admin/settings/branding#share_image', $uploaded->getHeaderLine('Location'));
        self::assertSame(['Share picture: uploaded. The preview shows how it was cropped.'], Flash::take($this->session));
        $state = $this->branding->service()->state();
        self::assertSame(['The river', 'O rio', true], [$state->alt(BrandAsset::ShareImage), $state->alt(BrandAsset::ShareImage, 'pt-PT'), $state->isUploaded(BrandAsset::ShareImage)]);
        self::assertStringContainsString('Reset the share picture to the generated one', (string) $this->handle('GET')->getBody());

        $this->handle('POST', ['asset' => 'logo', 'action' => 'save', 'alt' => ['en-GB' => 'Riverside, in blue']]);
        self::assertSame(['Logo: the text has been saved.'], Flash::take($this->session));
        $this->handle('POST', ['asset' => 'icon', 'action' => 'save'], $this->file(MediaFixture::png(512, 512)));
        self::assertSame(['Icon: uploaded.'], Flash::take($this->session));

        $this->handle('POST', ['asset' => 'share_image', 'action' => 'reset']);
        self::assertSame(['Share picture: the generated picture is back.'], Flash::take($this->session));
        self::assertFalse($this->branding->service()->state()->isUploaded(BrandAsset::ShareImage));
    }

    public function testExplainsARefusedUploadOnce(): void
    {
        $this->handle('POST', ['asset' => 'icon', 'action' => 'save'], $this->file(MediaFixture::png(600, 300)));
        $html = (string) $this->handle('GET')->getBody();
        self::assertStringContainsString('<section class="error-summary" id="branding-errors"', $html);
        self::assertStringContainsString('<a href="#icon-file">', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="icon-file-error"', $html);
        self::assertStringNotContainsString('branding-errors', (string) $this->handle('GET')->getBody(), 'shown once');

        foreach ([
            [['asset' => 'icon', 'action' => 'save'], null, 'Choose a picture to upload.'],
            [['asset' => 'logo', 'action' => 'save', 'alt' => 'not a list'], null, 'Choose a picture to upload.'],
            [['asset' => 'logo', 'action' => 'save'], new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_INI_SIZE), 'larger than this server accepts'],
            [['asset' => 'logo', 'action' => 'save'], new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_PARTIAL), 'Only part of the file arrived'],
            [['asset' => 'logo', 'action' => 'save'], new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_CANT_WRITE), 'could not be uploaded'],
            [['asset' => 'logo', 'action' => 'save'], new UploadedFile(Stream::create('x'), 6 * 1024 * 1024, UPLOAD_ERR_OK), 'larger than 5 MB'],
        ] as [$body, $file, $message]) {
            $this->handle('POST', $body, $file);
            self::assertStringContainsString($message, (string) $this->handle('GET')->getBody());
        }
    }

    public function testRefusesFormsThatArentBrandingForms(): void
    {
        $this->expectException(HttpBadRequestException::class);

        $this->handle('POST', ['asset' => 'banner']);
    }

    private function file(string $bytes): UploadedFile
    {
        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, 'picture', 'image/png');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handle(string $method, array $body = [], ?UploadedFile $file = null, ?User $user = null): ResponseInterface
    {
        $request = $this->admin->request($user ?? $this->ada, $method, BrandingAdminHandler::PATH, $body, $this->session);
        if ($file !== null) {
            $request = $request->withUploadedFiles(['file' => $file]);
        }

        return new BrandingAdminHandler($this->admin->shell(), $this->branding->service(), $this->branding->site)->handle($request);
    }
}
