<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\UserRepository;
use Slim\Exception\HttpBadRequestException;

/**
 * `POST /account/language`: the language switcher of the admin panel. The signed-in person chooses one of the
 * languages the admin panel is translated into, or one of the site's (for their emails), and goes back to the screen
 * they were on (`return`, a path on this site). The new language is announced on that screen, in that language.
 */
final readonly class AccountLanguageHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/language';

    public function __construct(
        private AccountPages $pages,
        private UserRepository $users,
        private Translations $translations,
        private Site $site,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->pages->user($request);
        if ($user === null) {
            return $this->pages->signIn();
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        if (!in_array($locale, [...$this->translations->locales(), ...$this->site->locales], true)) {
            throw new HttpBadRequestException($request, 'Choose one of the languages offered.');
        }
        $return = is_string($body['return'] ?? null) && preg_match('#^/(?![/\\\\])[^\\\\\s]*$#', $body['return']) === 1 ? $body['return'] : AccountPages::PATH;
        if ($user->locale() !== $locale) {
            $user->changeLocale($locale, $this->clock->now());
            $this->users->save($user);
            $this->events->dispatch(new AccountChanged((int) $user->id(), $user->id(), ['locale']));
        }
        Flash::add(Session::fromRequest($request), new Message('The admin panel is now in {language}.', ['language' => LocaleCode::ownName($locale)]));

        return $this->responses->createResponse(303)
            ->withHeader('Location', $return)
            ->withHeader('Cache-Control', 'no-store');
    }
}
