<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Http;

use SightPress\Core\Template\Message;
use Locale;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandImages;
use SightPress\Core\Branding\BrandingException;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Branding\BrandingState;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/settings/branding`: the logo, the icon and the share picture, each with a preview of what visitors get
 * (its `alt` says what it is), an upload field, what it shows in each of the site's languages (logo and share
 * picture), and "Reset to generated" once a picture was uploaded. The share picture's preview is the 1200 × 630
 * crop that was stored. Each result is said in the live region. Needs `settings.manage`.
 */
final readonly class BrandingAdminHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/branding';

    public const string PERMISSION = 'settings.manage';

    private const string ERROR = 'branding_admin.error';

    public function __construct(
        private AdminScreens $screens,
        private BrandingService $branding,
        private Site $site,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Actor::fromRequest($request)->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        if ($request->getMethod() === 'POST') {
            return $this->change($request);
        }
        $session = Session::fromRequest($request);
        $error = $session->get(self::ERROR);
        $session->remove(self::ERROR);
        $state = $this->branding->state();
        if ($state->version === '') {
            $state = $this->branding->regenerate();
        }

        return $this->screens->render($request, '@branding/admin.html.twig', [
            'assets' => array_map(fn (BrandAsset $asset): array => $this->view($state, $asset), BrandAsset::cases()),
            'error' => is_array($error) && is_string($error['asset'] ?? null) && Message::import($error['message'] ?? null) !== null ? ['asset' => $error['asset'], 'message' => Message::import($error['message'])] : null,
            'error_count' => is_array($error) ? 1 : 0,
            'action' => self::PATH,
        ], is_array($error) ? 422 : 200);
    }

    private function change(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $asset = BrandAsset::tryFrom(is_string($body['asset'] ?? null) ? $body['asset'] : '')
            ?? throw new HttpBadRequestException($request, 'This form isn’t one of the branding forms.');
        $what = self::title($asset);
        $actor = Actor::fromRequest($request)->userId;
        if (($body['action'] ?? null) === 'reset') {
            $this->branding->reset($asset);
            $this->events->dispatch(new SettingsChanged(['branding.' . $asset->value], $actor));

            return $this->screens->redirect($request, self::PATH . '#' . $asset->value, new Message('{what}: the generated picture is back.', ['what' => $what]));
        }

        $alts = [];
        foreach ($this->site->locales as $locale) {
            $value = is_array($body['alt'] ?? null) ? ($body['alt'][$locale] ?? null) : null;
            if (is_string($value)) {
                $alts[$locale] = $value;
            }
        }
        $upload = $request->getUploadedFiles()['file'] ?? null;
        $uploaded = $upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE;
        try {
            if (!$uploaded && ($asset === BrandAsset::Icon || $alts === [])) {
                throw new BrandingException('Choose a picture to upload.');
            }
            if ($uploaded) {
                $this->branding->set($asset, self::bytes($upload), $alts[$this->site->defaultLocale] ?? null);
            }
            if ($asset !== BrandAsset::Icon && $alts !== []) {
                $this->branding->describe($asset, $alts);
            }
            $this->events->dispatch(new SettingsChanged(['branding.' . $asset->value], $actor));
        } catch (BrandingException $exception) {
            Session::fromRequest($request)->set(self::ERROR, ['asset' => $asset->value, 'message' => $exception->text()->export()]);

            // No fragment: focus goes to the error summary, not to the section.
            return $this->screens->redirect($request, self::PATH);
        }

        return $this->screens->redirect($request, self::PATH . '#' . $asset->value, match (true) {
            $uploaded && $asset === BrandAsset::ShareImage => new Message('{what}: uploaded. The preview shows how it was cropped.', ['what' => $what]),
            $uploaded => new Message('{what}: uploaded.', ['what' => $what]),
            default => new Message('{what}: the text has been saved.', ['what' => $what]),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function view(BrandingState $state, BrandAsset $asset): array
    {
        $entry = $state->asset($asset);
        $file = $entry['file'] ?? null;
        $languages = [];
        foreach ($this->site->locales as $locale) {
            $name = Locale::getDisplayName($locale, 'en-GB');
            $languages[] = [
                'locale' => $locale,
                'name' => is_string($name) && $name !== '' ? $name : $locale,
                'value' => $locale === $this->site->defaultLocale ? ($state->alt($asset) ?? '') : ($state->alt[$asset->value . '@' . $locale] ?? ''),
            ];
        }

        return [
            'asset' => $asset->value,
            'title' => self::title($asset),
            'intro' => match ($asset) {
                BrandAsset::Logo => 'Shown at the top of every page and links to the home page. Without one, the site name is shown as text. SVG, PNG or WebP.',
                BrandAsset::Icon => new Message('Shown in browser tabs, bookmarks and on phone home screens. A square picture, at least {icon_min} × {icon_min} pixels: SVG, PNG or WebP.', ['icon_min' => (string) BrandImages::ICON_MIN]),
                BrandAsset::ShareImage => new Message('Shown when someone shares a page of the site. At least {share_width} × {share_height} pixels: JPEG, PNG or WebP. A larger picture is cropped from the middle.', ['share_width' => (string) BrandImages::SHARE_WIDTH, 'share_height' => (string) BrandImages::SHARE_HEIGHT]),
            },
            'accept' => $asset === BrandAsset::ShareImage ? 'image/jpeg,image/png,image/webp' : 'image/svg+xml,image/png,image/webp',
            'uploaded' => $state->isUploaded($asset),
            'preview' => $file === null ? null : ($asset === BrandAsset::Icon ? '/' : '/branding/') . $file . '?v=' . rawurlencode($state->version),
            'preview_alt' => match ($asset) {
                BrandAsset::Logo => new Message('The logo now: {name}', ['name' => $state->alt($asset) ?? $this->site->name]),
                BrandAsset::Icon => new Message('The icon of {name} now', ['name' => $this->site->name]),
                BrandAsset::ShareImage => new Message('The share picture now: {name}', ['name' => $state->alt($asset) ?? $this->site->name]),
            },
            'width' => $entry['width'] ?? null,
            'height' => $entry['height'] ?? null,
            'languages' => $asset === BrandAsset::Icon ? [] : $languages,
            'alt_hint' => $asset === BrandAsset::Logo
                ? 'What the logo says or shows, for people who can’t see it. Leave it empty to use the site name. Never “logo”.'
                : 'What the picture shows, for people who can’t see it.',
        ];
    }

    private static function title(BrandAsset $asset): string
    {
        return match ($asset) {
            BrandAsset::Logo => 'Logo',
            BrandAsset::Icon => 'Icon',
            BrandAsset::ShareImage => 'Share picture',
        };
    }

    private static function bytes(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new BrandingException(match ($upload->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts. Make it smaller and try again.',
                UPLOAD_ERR_PARTIAL => 'Only part of the file arrived. Try uploading it again.',
                default => 'The file could not be uploaded. Try again.',
            });
        }
        if (($upload->getSize() ?? 0) > BrandImages::MAX_BYTES) {
            throw new BrandingException('The file is larger than 5 MB. Make it smaller and try again.');
        }

        return (string) $upload->getStream();
    }
}
