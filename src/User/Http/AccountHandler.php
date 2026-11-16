<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Contract\AccountChanged;
use SightPress\Core\User\UserRepository;

/**
 * `/account`: shows *My account*, and saves the names, time zone and language. A new name makes the generated
 * avatar again; every change is announced with {@see AccountChanged} for the audit log.
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private AccountPages $pages,
        private FormValidator $validator,
        private UserRepository $users,
        private AvatarService $avatars,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->pages->user($request);
        if ($user === null) {
            return $this->pages->signIn();
        }
        if ($request->getMethod() !== 'POST') {
            return $this->pages->page($request, $user);
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit($this->pages->details($user), is_array($body) ? $body : []);
        if (!$form->isValid()) {
            return $this->pages->page($request, $user, details: $form);
        }

        $before = [
            'first_name' => $user->firstName(),
            'last_name' => $user->lastName(),
            'display_name' => $user->displayName(),
            'timezone' => $user->timezone(),
            'locale' => $user->locale(),
        ];
        $now = $this->clock->now();
        $displayName = $form->text('display_name');
        $user->rename($form->text('first_name'), $form->text('last_name'), $displayName === '' ? null : $displayName, $now);
        $user->changeTimezone($form->text('timezone'), $now);
        if (array_key_exists('locale', $form->values())) {
            $locale = $form->text('locale');
            $user->changeLocale($locale === 'default' ? null : $locale, $now);
        }
        $after = [
            'first_name' => $user->firstName(),
            'last_name' => $user->lastName(),
            'display_name' => $user->displayName(),
            'timezone' => $user->timezone(),
            'locale' => $user->locale(),
        ];
        $changed = array_keys(array_diff_assoc(array_map(strval(...), $after), array_map(strval(...), $before)));
        if ($changed === []) {
            return $this->pages->redirect($request, 'You didn’t change anything.');
        }
        if (array_intersect($changed, ['first_name', 'last_name']) !== []) {
            $this->avatars->nameChanged($user);
            $changed[] = 'avatar';
        }
        $this->users->save($user);
        $this->events->dispatch(new AccountChanged((int) $user->id(), $user->id(), $changed));

        return $this->pages->redirect($request, 'Your details have been saved.');
    }
}
