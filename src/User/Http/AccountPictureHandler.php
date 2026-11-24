<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\User\Avatar\AvatarException;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\UserRepository;

/**
 * `POST /account/picture`: uploads a new profile picture. `POST /account/picture/remove`: goes back to the
 * initials. The result is announced on *My account*; a refused picture is explained in the error summary.
 */
final readonly class AccountPictureHandler implements RequestHandlerInterface
{
    public const string REMOVE_PATH = '/account/picture/remove';

    public function __construct(
        private AccountPages $pages,
        private UserRepository $users,
        private AvatarService $avatars,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->pages->user($request);
        if ($user === null) {
            return $this->pages->signIn();
        }

        if ($request->getUri()->getPath() === self::REMOVE_PATH) {
            $this->avatars->remove($user);
            $message = 'Your picture has been removed. Your initials are shown instead.';
        } else {
            $upload = $request->getUploadedFiles()['picture'] ?? null;
            try {
                if (!$upload instanceof UploadedFileInterface) {
                    throw new AvatarException('Choose a picture to upload.');
                }
                $this->avatars->upload($user, $upload);
            } catch (AvatarException $exception) {
                return $this->pages->page($request, $user, picture: $this->pages->picture()->withErrors(['picture' => $exception->text()]));
            }
            $message = 'Your new picture has been saved.';
        }

        $this->users->save($user);
        $this->events->dispatch(new AccountChanged((int) $user->id(), $user->id(), ['avatar']));

        return $this->pages->redirect($request, $message);
    }
}
