<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Tenant\Site;

/**
 * `/admin/media`: the upload form (progress is announced by `js/media-upload.js`; without JavaScript the form is
 * sent the usual way) and the files, newest first, as a grid of cards or a data table. The chosen view is kept in
 * the session. Needs `media.upload`.
 */
final readonly class MediaLibraryHandler implements RequestHandlerInterface
{
    public const int PER_PAGE = 48;
    public const string VIEW_KEY = 'media.view';
    public const string ERROR_KEY = 'media.upload_error';

    public function __construct(
        private AdminScreens $screens,
        private MediaRepository $media,
        private MediaTypes $types,
        private MediaUrlGenerator $urls,
        private Site $site,
    ) {
    }

    public function form(): Form
    {
        return Form::create('upload', new Field(
            'file',
            'File to upload',
            FieldType::File,
            hint: $this->types->summary() . ' New files stay unpublished until an editor publishes them.',
            accept: $this->types->accept(),
        ));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        MediaAdmin::actor($request);
        $session = Session::fromRequest($request);
        $query = $request->getQueryParams();
        if (in_array($query['view'] ?? null, ['grid', 'list'], true)) {
            $session->set(self::VIEW_KEY, $query['view']);
        }
        $view = $session->get(self::VIEW_KEY) === 'list' ? 'list' : 'grid';

        $form = $this->form();
        $error = $session->get(self::ERROR_KEY);
        $session->remove(self::ERROR_KEY);
        if (is_string($error)) {
            $form = $form->withErrors(['file' => $error]);
        }

        $total = $this->media->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = is_numeric($query['page'] ?? null) ? min($pages, max(1, (int) $query['page'])) : 1;
        $items = array_map(fn (Media $media): array => [
            'media' => $media,
            'path' => MediaAdmin::path($media),
            'thumbnail' => $media->kind() === MediaKind::Image
                ? $this->urls->url($media, $media->variantWidths() === [] ? null : min($media->variantWidths()))
                : null,
            'type' => $this->types->label($media->mimeType()) ?? $media->mimeType(),
            'size' => MediaLimits::describe($media->size()),
            'alt' => $media->altText($this->site->defaultLocale),
        ], $this->media->newest(self::PER_PAGE, ($page - 1) * self::PER_PAGE));

        return $this->screens->render($request, '@media_admin/library.html.twig', [
            'form' => $form,
            'items' => $items,
            'view' => $view,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ], $error === null ? 200 : 422);
    }
}
