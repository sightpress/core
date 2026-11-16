<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Admin;

use DateTimeImmutable;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\Http\MediaDeleteHandler;
use SightPress\Core\Media\Admin\Http\MediaItemHandler;
use SightPress\Core\Media\Admin\Http\MediaLibraryHandler;
use SightPress\Core\Media\Admin\Http\MediaUploadHandler;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(MediaAdmin::class)]
#[CoversClass(MediaLibraryHandler::class)]
#[CoversClass(MediaUploadHandler::class)]
#[CoversClass(MediaItemHandler::class)]
#[CoversClass(MediaDeleteHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaAdminHandlersTest extends TestCase
{
    private AdminFixture $admin;

    private MediaFixture $media;

    private Session $session;

    private User $ada;

    private User $cleo;

    private int $stored = 0;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->media = new MediaFixture();
        $this->session = new Session();
        $this->ada = $this->admin->auth->user('ada@example.org', 'admin');
        $this->cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testOnlyPeopleWhoUploadMediaUseTheLibrary(): void
    {
        $max = $this->admin->auth->user('max@example.org', 'member');

        $this->expectException(HttpForbiddenException::class);
        $this->library($max);
    }

    public function testAnEmptyLibraryOffersTheUploadForm(): void
    {
        $html = (string) $this->library($this->cleo)->getBody();

        self::assertStringContainsString('<title>Media - Admin - Demo</title>', $html);
        self::assertStringContainsString('<a href="/admin/media" aria-current="page">Media</a>', $html);
        self::assertStringContainsString('action="/admin/media/upload" method="post" enctype="multipart/form-data"', $html);
        self::assertMatchesRegularExpression('#<input type="file"[^>]+accept="\.jpg,\.png,#', $html);
        self::assertStringContainsString('<p id="media-upload-status" class="media-upload__status" aria-live="polite"></p>', $html);
        self::assertStringContainsString('There are no files yet. Upload one above.', $html);
        self::assertMatchesRegularExpression('#<script src="/assets/[^"]*js/media-upload\.js[^"]*" data-form="upload" defer></script>#', $html);
    }

    public function testFilesAreAGridOfCardsOrADataTableAndTheChoiceIsKept(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));
        $this->upload('report.pdf', "%PDF-1.4\n%%EOF\n");
        $picture->describe('en-GB', 'A lighthouse at dusk', null, new DateTimeImmutable());
        $this->media->repository()->save($picture);

        $grid = (string) $this->library($this->cleo)->getBody();
        self::assertStringContainsString('<a href="?view=grid" aria-current="true">Grid</a>', $grid);
        self::assertMatchesRegularExpression('#<img class="media-card__thumb" src="/media/[0-9a-f]{32}/320w/lighthouse\.jpg" alt="" loading="lazy">#', $grid);
        self::assertStringContainsString('<a class="media-card__name" href="/admin/media/' . $picture->id() . '">lighthouse.jpg</a>', $grid);
        self::assertStringContainsString('<span class="media-card__thumb media-card__thumb--file" aria-hidden="true">PDF</span>', $grid);
        self::assertStringContainsString('JPEG, ', $grid);

        $list = (string) $this->library($this->cleo, '?view=list')->getBody();
        self::assertStringContainsString('<a href="?view=list" aria-current="true">List</a>', $list);
        self::assertStringContainsString('<caption id="media-caption">Files, newest first (2 in all)</caption>', $list);
        self::assertStringContainsString('<td>A lighthouse at dusk</td>', $list);
        self::assertStringContainsString('<td>Not needed</td>', $list);
        self::assertStringContainsString('<td>Not published</td>', $list);

        self::assertStringContainsString('<caption id="media-caption">', (string) $this->library($this->cleo)->getBody(), 'the view is remembered');
    }

    public function testMissingAltTextIsPointedOutInBothViews(): void
    {
        $this->upload('dusk.png', MediaFixture::png());

        self::assertStringContainsString('<strong>alt text missing</strong>', (string) $this->library($this->cleo, '?view=grid')->getBody());
        self::assertStringContainsString('<td><strong>Missing</strong></td>', (string) $this->library($this->cleo, '?view=list')->getBody());
    }

    public function testLongLibrariesArePaged(): void
    {
        $format = new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF');
        for ($i = 1; $i <= MediaLibraryHandler::PER_PAGE + 1; $i++) {
            $this->media->repository()->save(new Media(sprintf('%032x', $i), sprintf('%064x', $i), $format, 'f.pdf', "File {$i}.pdf", 1, null, null, [], null, new DateTimeImmutable(sprintf('2026-11-16 00:00:%02d UTC', $i % 60))->modify("+{$i} minutes")));
        }

        $first = (string) $this->library($this->cleo)->getBody();
        self::assertStringContainsString('<nav class="pagination" aria-label="Pages of files">', $first);
        self::assertStringContainsString('File 49.pdf', $first);
        self::assertStringNotContainsString('>File 1.pdf<', $first);

        self::assertStringContainsString('>File 1.pdf<', (string) $this->library($this->cleo, '?page=9')->getBody(), 'past the end shows the last page');
    }

    public function testUploadingGoesToTheNewFileAndSaysWhatToDoNext(): void
    {
        $response = $this->send($this->cleo, new UploadedFile(Stream::create(MediaFixture::png()), 100, UPLOAD_ERR_OK, 'Dusk.png'));

        $media = $this->media->repository()->newest(1)[0];
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media/' . $media->id(), $response->getHeaderLine('Location'));
        self::assertSame(['Dusk.png has been uploaded. Describe it below, so everyone can understand it.'], Flash::take($this->session));
        self::assertSame($this->cleo->id(), $media->uploadedBy());

        $this->send($this->cleo, new UploadedFile(Stream::create(MediaFixture::png()), 100, UPLOAD_ERR_OK, 'Again.png'));
        self::assertSame(['Dusk.png is already in the library, so it wasn’t added again. Here it is.'], Flash::take($this->session));
    }

    public function testTheProgressScriptGetsTheAddressAsJson(): void
    {
        $response = $this->send($this->cleo, new UploadedFile(Stream::create(MediaFixture::png()), 100, UPLOAD_ERR_OK, 'Dusk.png'), xhr: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(json_encode(['location' => '/admin/media/' . $this->media->repository()->newest(1)[0]->id()], JSON_UNESCAPED_SLASHES), (string) $response->getBody());

        $refused = $this->send($this->cleo, null, xhr: true);
        self::assertSame('{"location":"/admin/media"}', (string) $refused->getBody());
    }

    public function testARefusedFileIsExplainedInTheLibrarysErrorSummary(): void
    {
        $response = $this->send($this->cleo, new UploadedFile(Stream::create('<svg xmlns="http://www.w3.org/2000/svg"/>'), 40, UPLOAD_ERR_OK, 'logo.svg'));
        self::assertSame('/admin/media', $response->getHeaderLine('Location'));

        $library = $this->library($this->cleo);
        self::assertSame(422, $library->getStatusCode());
        $html = (string) $library->getBody();
        self::assertStringContainsString('<title>1 error: Media - Admin - Demo</title>', $html);
        self::assertStringContainsString('This type of file can&#039;t be uploaded.', $html);
        self::assertSame(200, $this->library($this->cleo)->getStatusCode(), 'the error is shown once');

        $this->send($this->cleo, null);
        self::assertStringContainsString('Choose a file to upload.', (string) $this->library($this->cleo)->getBody());
    }

    public function testAPicturesPageHasAPreviewItsFactsAndADetailsForm(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));

        $html = (string) $this->item($this->cleo, $picture)->getBody();

        self::assertStringContainsString('<title>lighthouse.jpg - Admin - Demo</title>', $html);
        self::assertMatchesRegularExpression('#<img src="/media/[0-9a-f]{32}/640w/lighthouse\.jpg" alt="" width="800" height="600">#', $html);
        self::assertStringContainsString('This picture has no alt text yet.', $html);
        self::assertStringContainsString('<dt>Dimensions</dt><dd>800 × 600 pixels</dd>', $html);
        self::assertStringContainsString('<dt>Status</dt><dd>Not published</dd>', $html);
        self::assertMatchesRegularExpression('#<label for="details-alt-0">\s*Alt text \(optional\)#', $html);
        self::assertStringNotContainsString('name="transcript"', $html);
        self::assertStringNotContainsString('name="published"', $html, 'contributors may not publish');
        self::assertStringContainsString('Only editors and admins can publish or unpublish files.', $html);
        self::assertStringContainsString('<a href="/admin/media/' . $picture->id() . '/delete">Delete this file</a>', $html, 'their own unpublished file');
    }

    public function testDescribingAFileSavesItAndSaysSo(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));

        $response = $this->item($this->ada, $picture, ['alt_0' => 'A lighthouse at dusk', 'caption_0' => 'Our lighthouse', 'published' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['The details of lighthouse.jpg have been saved.'], Flash::take($this->session));
        self::assertSame(['A lighthouse at dusk', 'Our lighthouse', true], [$picture->altText('en-GB'), $picture->caption('en-GB'), $picture->isPublished()]);
        $html = (string) $this->item($this->ada, $picture)->getBody();
        self::assertMatchesRegularExpression('#<img src="[^"]+" alt="A lighthouse at dusk"#', $html);
        self::assertMatchesRegularExpression('#value="1" checked name="published"#', $html);

        $this->item($this->ada, $picture, ['alt_0' => 'A lighthouse at dusk', 'caption_0' => 'Our lighthouse', 'published' => '1']);
        self::assertSame(['Nothing was changed.'], Flash::take($this->session));
    }

    public function testContributorsCantPublishWhateverTheySend(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));

        $this->item($this->cleo, $picture, ['alt_0' => 'A lighthouse', 'caption_0' => '', 'published' => '1']);

        self::assertFalse($picture->isPublished());
        self::assertSame('A lighthouse', $picture->altText('en-GB'));
    }

    public function testTooLongTextIsExplained(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));

        $response = $this->item($this->cleo, $picture, ['alt_0' => str_repeat('a', Media::ALT_MAX_LENGTH + 1), 'caption_0' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Alt text must be 1000 characters or fewer', (string) $response->getBody());
        self::assertNull($picture->altText('en-GB'));
    }

    public function testVideoAndAudioHaveATranscriptAndPlayersAndEachLanguageItsFields(): void
    {
        $video = $this->stored(new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4'), 'Tour.mp4');
        $audio = $this->stored(new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'Talk.mp3');
        $site = PageFixture::site(['en-GB', 'pt-BR']);

        $html = (string) $this->item($this->ada, $video, site: $site)->getBody();
        self::assertMatchesRegularExpression('#<video src="/media/[0-9a-f]{32}/tour\.mp4" controls preload="metadata"></video>#', $html);
        self::assertMatchesRegularExpression('#Caption in English \(United Kingdom\) \(optional\)#', $html);
        self::assertMatchesRegularExpression('#Caption in Portuguese \(Brazil\) \(optional\)#', $html);
        self::assertStringNotContainsString('Alt text', $html);
        self::assertStringContainsString('name="transcript"', $html);

        self::assertMatchesRegularExpression('#<audio src="[^"]+" controls preload="metadata"></audio>#', (string) $this->item($this->ada, $audio, site: $site)->getBody());
        $this->item($this->ada, $audio, ['caption_0' => '', 'caption_1' => 'Palestra', 'transcript' => 'Hello.', 'published' => ''], site: $site);
        self::assertSame(['Palestra', 'Hello.'], [$audio->caption('pt-BR'), $audio->transcript()]);
    }

    public function testDocumentsHaveNoPreview(): void
    {
        $pdf = $this->stored(new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'), 'Report.pdf');

        $html = (string) $this->item($this->cleo, $pdf)->getBody();

        self::assertStringNotContainsString('<img src', $html);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('<dt>Dimensions</dt>', $html);
        self::assertStringNotContainsString('Delete this file', $html, 'someone else’s file');
    }

    public function testUnknownFilesAreNotFound(): void
    {
        foreach (['999', 'x', null] as $id) {
            try {
                $this->itemHandler()->handle($this->request($this->cleo, 'GET', '/admin/media/x', [], $id));
                self::fail('Found ' . var_export($id, true));
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDeletingAsksFirstThenDeletes(): void
    {
        $picture = $this->upload('lighthouse.jpg', MediaFixture::jpeg(800, 600));
        $id = (int) $picture->id();

        $html = (string) $this->delete($this->ada, $picture, 'GET')->getBody();
        self::assertStringContainsString('<title>Delete lighthouse.jpg? - Admin - Demo</title>', $html);
        self::assertStringContainsString('<form action="/admin/media/' . $id . '/delete" method="post">', $html);
        self::assertStringContainsString('Delete lighthouse.jpg</button>', $html);
        self::assertStringContainsString('<a href="/admin/media/' . $id . '">Keep the file and go back</a>', $html);
        self::assertNotNull($this->media->repository()->find($id));

        $response = $this->delete($this->ada, $picture, 'POST');
        self::assertSame('/admin/media', $response->getHeaderLine('Location'));
        self::assertSame(['lighthouse.jpg has been deleted.'], Flash::take($this->session));
        self::assertNull($this->media->repository()->find($id));
        self::assertSame([], $this->media->stored());
    }

    public function testWhoMayDelete(): void
    {
        $own = $this->upload('mine.png', MediaFixture::png());
        $theirs = $this->stored(new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'), 'Theirs.pdf');
        $contributor = new Actor((int) $this->cleo->id(), ['media.upload']);

        self::assertTrue(MediaAdmin::canDelete($contributor, $own));
        self::assertFalse(MediaAdmin::canDelete($contributor, $theirs));
        self::assertFalse(MediaAdmin::canDelete(new Actor(null, ['media.upload']), $theirs));
        self::assertTrue(MediaAdmin::canDelete(new Actor(1, ['media.upload', 'media.publish']), $theirs));
        $own->publish(new DateTimeImmutable());
        self::assertFalse(MediaAdmin::canDelete($contributor, $own), 'not once it is published');

        $this->expectException(HttpForbiddenException::class);
        $this->delete($this->cleo, $own, 'GET');
    }

    private function library(User $actor, string $query = ''): ResponseInterface
    {
        return new MediaLibraryHandler($this->admin->shell(), $this->media->repository(), new MediaTypes(), new OriginMediaUrlGenerator(), $this->admin->auth->site)
            ->handle($this->request($actor, 'GET', MediaAdmin::PATH . $query));
    }

    private function send(User $actor, ?UploadedFile $file, bool $xhr = false): ResponseInterface
    {
        $request = $this->request($actor, 'POST', MediaUploadHandler::PATH)->withUploadedFiles($file === null ? [] : ['file' => $file]);

        return new MediaUploadHandler($this->admin->shell(), $this->media->uploader(), $this->admin->auth->http)
            ->handle($xhr ? $request->withHeader('X-Requested-With', 'XMLHttpRequest') : $request);
    }

    /**
     * @param array<string, string> $body
     */
    private function item(User $actor, Media $media, array $body = [], ?Site $site = null): ResponseInterface
    {
        return $this->itemHandler($site)
            ->handle($this->request($actor, $body === [] ? 'GET' : 'POST', MediaAdmin::path($media), $body, (string) $media->id()));
    }

    private function itemHandler(?Site $site = null): MediaItemHandler
    {
        return new MediaItemHandler(
            $this->admin->shell(),
            $this->admin->auth->validator(),
            new MediaAdmin($this->media->repository()),
            $this->editor(),
            new MediaTypes(),
            new OriginMediaUrlGenerator(),
            $site ?? $this->admin->auth->site,
        );
    }

    private function delete(User $actor, Media $media, string $method): ResponseInterface
    {
        return new MediaDeleteHandler($this->admin->shell(), new MediaAdmin($this->media->repository()), $this->editor())
            ->handle($this->request($actor, $method, MediaAdmin::path($media, '/delete'), $method === 'POST' ? ['confirm' => '1'] : [], (string) $media->id()));
    }

    private function editor(): MediaEditor
    {
        return new MediaEditor($this->media->repository(), $this->media->files(), $this->media->clock, $this->media);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(User $actor, string $method, string $path, array $body = [], ?string $id = null): ServerRequestInterface
    {
        $request = $this->admin->request($actor, $method, $path, $body, $this->session);

        return $id === null ? $request : $request->withAttribute(MediaAdmin::ID_ATTRIBUTE, $id);
    }

    private function upload(string $name, string $contents): Media
    {
        return $this->media->uploader()->upload($this->media->file($name, $contents), $name, $this->cleo->id())->media;
    }

    private function stored(MediaFormat $format, string $name): Media
    {
        $this->stored++;
        $media = new Media(sprintf('%032x', 1000 + $this->stored), sprintf('%064x', 1000 + $this->stored), $format, strtolower($name), $name, 10, null, null, [], 999, new DateTimeImmutable());
        $this->media->repository()->save($media);

        return $media;
    }
}
