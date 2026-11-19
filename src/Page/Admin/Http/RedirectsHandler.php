<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Page\Entity\PageRedirect;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRedirects;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/redirects`: the old addresses of pages and where they lead, in a table with a "Remove" button each, and a
 * form that adds a redirect (an old address no page uses → an address on the site). Pages that moved add theirs by
 * themselves; pages deleted for good leave theirs as "gone". Needs `page.edit.any`.
 */
final readonly class RedirectsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/redirects';

    public const string PERMISSION = 'page.edit.any';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private PageRedirects $redirects,
        private PageAddresses $addresses,
        private Site $site,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'add-redirect',
            new Field('from', 'Old address', hint: 'The address that should lead somewhere else, starting with a slash: for example /old-news, or /pt-pt/noticias for a page in Portuguese. No page may use it.', requiredMessage: 'Enter the old address'),
            new Field('to', 'New address', hint: 'Where it should lead, in the same language: for example /news.', requiredMessage: 'Enter the new address'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $form = self::form();
        $from = $request->getQueryParams()['from'] ?? null;
        if (is_string($from) && $from !== '') {
            // From "Add a redirect" on the Analytics screen's pages not found.
            $form = $form->withValues(['from' => mb_substr($from, 0, 300)]);
        }
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            if (($body['op'] ?? null) === 'remove') {
                return $this->remove($request, $body, $actor);
            }
            $form = $this->validator->submit($form, $body);
            if ($form->isValid()) {
                [$locale, $from] = $this->addresses->split(self::path($form->text('from')));
                [$toLocale, $to] = $this->addresses->split(self::path($form->text('to')));
                $from = rawurldecode($from);
                $to = rawurldecode($to);
                $error = match (true) {
                    $from === '' => ['from', new Message('The home page can’t be redirected. Enter the address of another page.')],
                    $toLocale !== $locale => ['to', new Message('The new address must be in the same language as the old one ({language}).', ['language' => new LanguageName($locale)])],
                    default => null,
                };
                if ($error === null) {
                    try {
                        $this->redirects->add($locale, $from, $to, $actor->userId);

                        return $this->screens->redirect($request, self::PATH, new Message('Added the redirect: {from} now leads to {to}.', ['from' => $this->url($locale, $from), 'to' => $this->url($locale, $to)]));
                    } catch (PageException $exception) {
                        $error = [$this->addresses->findIn($locale, $from) === null ? 'to' : 'from', $exception->text()];
                    }
                }
                $form = $form->withErrors([$error[0] => $error[1]]);
            }
        }

        return $this->screens->render($request, '@page_admin/redirects.html.twig', [
            'redirects' => array_map(fn (PageRedirect $redirect): array => [
                'id' => $redirect->id(),
                'from' => $this->url($redirect->locale(), $redirect->source()),
                'to' => $redirect->target() === null ? null : $this->url($redirect->locale(), $redirect->target()),
                'language' => new LanguageName($redirect->locale()),
                'changed' => $redirect->updatedAt(),
            ], $this->redirects->all()),
            'languages' => count($this->site->locales) > 1,
            'form' => $form,
            'action' => self::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function remove(ServerRequestInterface $request, array $body, Actor $actor): ResponseInterface
    {
        $id = $body['id'] ?? null;
        $redirect = is_string($id) && ctype_digit($id) ? $this->redirects->get((int) $id) : null;
        if ($redirect === null) {
            throw new HttpBadRequestException($request, 'That redirect isn’t there any more.');
        }
        $this->redirects->remove($redirect, $actor->userId);

        return $this->screens->redirect($request, self::PATH, new Message('Removed the redirect from {from}. That address now answers “not found”.', ['from' => $this->url($redirect->locale(), $redirect->source())]));
    }

    /**
     * The path of an address typed in the form, also when it was pasted as a full web address.
     */
    private static function path(string $address): string
    {
        $path = str_contains($address, '://') ? parse_url($address, PHP_URL_PATH) : $address;

        return is_string($path) ? $path : '';
    }

    private function url(string $locale, string $path): string
    {
        $url = $this->addresses->prefixOf($locale) . '/' . $path;

        return $url === '/' ? '/' : rtrim($url, '/');
    }
}
