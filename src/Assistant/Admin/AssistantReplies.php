<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\ViewerPreferences;

/**
 * Answers to `js/assistant.js` (which sends `X-Requested-With: XMLHttpRequest`): JSON, never cached, with texts in
 * the person's admin language.
 */
final readonly class AssistantReplies
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private Translations $translations,
    ) {
    }

    public static function wantsJson(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function json(ServerRequestInterface $request, array $data, int $status = 200): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    public function text(ServerRequestInterface $request, Message $message): string
    {
        return $this->translations->translate($message, [], $this->locale($request));
    }

    public function locale(ServerRequestInterface $request): string
    {
        $own = $request->getAttribute(ViewerPreferences::class);
        $locale = $own instanceof ViewerPreferences ? ($own->locale ?? '') : '';

        return $this->translations->catalogueLocale($locale !== '' ? $locale : $this->translations->defaultLocale());
    }
}
