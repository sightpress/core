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
 * `POST /admin/assistant/insert`: "Insert" on the assistant's result page without JavaScript (and a draft's "Add to
 * the end of the page" with it). Keeps the suggestion for the screen it came from ({@see AssistantInsert}), which
 * puts it in the field (or adds the draft's blocks) without saving, and goes back there, at the field.
 */
final readonly class AssistantInsertHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant/insert';

    /** Longest suggestion that is put in a field. */
    public const int MAX_TEXT = 10000;

    /** Longest draft (blocks as JSON) that is added to a page. */
    public const int MAX_BLOCKS = 200000;

    public function __construct(private AdminScreens $screens)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $ask = AssistantAsk::fromBody($body);
        $field = is_string($body['field'] ?? null) ? $body['field'] : '';
        $blocks = $field === 'blocks';
        $text = is_string($body['text'] ?? null) ? trim(mb_substr($body['text'], 0, $blocks ? self::MAX_BLOCKS : self::MAX_TEXT)) : '';
        if (($ask->field === null && !$blocks) || $text === '' || ($blocks && !self::isBlockList($text))) {
            return $this->screens->redirect($request, $ask->return);
        }
        $path = (string) parse_url($ask->return, PHP_URL_PATH);
        AssistantInsert::keep(Session::fromRequest($request), $path, $blocks ? 'blocks' : (string) $ask->fieldName(), $text);

        return $this->screens->redirect(
            $request,
            $ask->return . ($ask->target === null ? '' : '#' . $ask->target),
            $blocks
                ? new Message('The draft is at the end of the page’s blocks. Check it, then save.')
                : new Message('The suggestion is in “{field}”. Check it, then save.', ['field' => new Message(AssistantAskHandler::FIELD_LABELS[(string) $ask->field])]),
        );
    }

    private static function isBlockList(string $json): bool
    {
        $blocks = json_decode($json, true);

        return is_array($blocks) && $blocks !== [] && array_is_list($blocks);
    }
}
