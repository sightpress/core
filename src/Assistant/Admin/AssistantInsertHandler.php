<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Assistant\Contract\AssistantInsert;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\Message;

/**
 * `POST /admin/assistant/insert`: "Insert" on the assistant's result page without JavaScript. Keeps the suggestion
 * for the screen it came from ({@see AssistantInsert}), which puts it in the field without saving, and goes back
 * there, at the field.
 */
final readonly class AssistantInsertHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant/insert';

    /** Longest suggestion that is put in a field. */
    public const int MAX_TEXT = 10000;

    public function __construct(private AdminScreens $screens)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $ask = AssistantAsk::fromBody($body);
        $text = is_string($body['text'] ?? null) ? trim(mb_substr($body['text'], 0, self::MAX_TEXT)) : '';
        if ($ask->field === null || $text === '') {
            return $this->screens->redirect($request, $ask->return);
        }
        $path = (string) parse_url($ask->return, PHP_URL_PATH);
        AssistantInsert::keep(Session::fromRequest($request), $path, $ask->field, $text);

        return $this->screens->redirect(
            $request,
            $ask->return . ($ask->target === null ? '' : '#' . $ask->target),
            new Message('The suggestion is in “{field}”. Check it, then save.', ['field' => new Message(AssistantAskHandler::FIELD_LABELS[$ask->field])]),
        );
    }
}
