<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;

/**
 * `POST /admin/assistant/test`: "Test connection" on the AI assistant screen sends a tiny request (counted like any
 * use) and says whether it worked, in the screen's live region: as JSON for `js/assistant.js`, or as the message of
 * the screen it goes back to.
 */
final readonly class AssistantTestHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant/test';

    public function __construct(
        private AdminScreens $screens,
        private Assistant $assistant,
        private AssistantSetup $setup,
        private AssistantReplies $replies,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AssistantModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        try {
            $this->assistant->test($actor->userId);
            $provider = $this->setup->configuration()->provider;
            $message = new Message('The connection works: {service} answered.', ['service' => $provider?->label() ?? '']);
            $ok = true;
        } catch (AssistantError $error) {
            $message = new Message('The connection didn’t work. {reason}', ['reason' => $error->reason]);
            $ok = false;
        }
        if (AssistantReplies::wantsJson($request)) {
            return $this->replies->json($request, ['ok' => $ok, 'message' => $this->replies->text($request, $message)]);
        }

        return $this->screens->redirect($request, AssistantSettingsHandler::PATH . '#assistant-test-title', $message);
    }
}
