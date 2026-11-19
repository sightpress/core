<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Assistant\Task\TranslateTask;
use SightPress\Core\Assistant\Task\TranslationSegments;
use SightPress\Core\Page\Contract\TranslationDrafts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;

/**
 * `POST /admin/assistant/translate` (A-6): "Draft a translation into … with the AI assistant" in the page editor's
 * Translations section. Sends the page's texts in one language to the `translate` task and saves the answer as a
 * draft translation marked as the assistant's ({@see TranslationDrafts}), with the same blocks, media and links, then
 * opens it in the editor. A plain form: it changes the site, so it is never a suggestion in the panel.
 */
final readonly class AssistantTranslateHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant/translate';

    public function __construct(
        private AdminScreens $screens,
        private Assistant $assistant,
        private TranslationDrafts $drafts,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? $body[$name] : '';
        $pageId = preg_match('/^[1-9]\d{0,9}$/', $text('page')) === 1 ? (int) $text('page') : 0;
        $from = $text('from');
        $to = $text('to');
        $back = '/admin/pages/' . $pageId . ($from === $this->site->defaultLocale || !in_array($from, $this->site->locales, true) ? '' : '?locale=' . rawurlencode($from));
        $source = $from === '' || $to === '' ? null : $this->drafts->source($actor, $pageId, $from, $to);
        if ($source === null) {
            return $this->screens->redirect($request, $pageId === 0 ? '/admin/pages' : $back, new Message('The translation can’t be started: the page changed, or you can’t change it now.'));
        }
        $segments = TranslationSegments::of($source);
        if (array_sum(array_map(mb_strlen(...), $segments)) > TranslateTask::MAX_CHARACTERS || count($segments) > TranslateTask::MAX_SEGMENTS) {
            return $this->screens->redirect($request, $back, new Message('This page is too long for the assistant to translate in one go. Start the translation with “Translate”, then ask for parts of it with “Rewrite text”.'));
        }
        try {
            $result = $this->assistant->run(TranslateTask::ID, ['from' => $from, 'to' => $to, 'segments' => $segments], new SiteAssistantContext($to === '' ? 'en-GB' : $to, $actor, 'page_editor'), $actor);
        } catch (AssistantError $error) {
            return $this->screens->redirect($request, $back, new Message('The assistant couldn’t draft the translation. {reason}', ['reason' => $error->reason]));
        }
        $translated = json_decode(is_string($result->suggestion->fields['segments'] ?? null) ? $result->suggestion->fields['segments'] : '[]', true);
        $problem = $this->drafts->save($actor, $pageId, $from, $to, TranslationSegments::apply($source, is_array($translated) ? array_values(array_map(strval(...), array_filter($translated, is_string(...)))) : []));
        if ($problem !== null) {
            return $this->screens->redirect($request, $back, $problem);
        }

        return $this->screens->redirect($request, '/admin/pages/' . $pageId . ($to === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($to)) . '#machine-drafted', new Message(
            'The AI assistant drafted the {language} translation. Read it and change what needs changing, then save it or choose “I checked this translation”. It can’t be published before.',
            ['language' => new LanguageName($to)],
        ));
    }
}
