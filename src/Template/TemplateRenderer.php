<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SightPress\Core\Http\CurrentRequest;
use Twig\Environment;

/**
 * Renders a template for a request; handlers use it to build HTML responses.
 */
final readonly class TemplateRenderer
{
    public function __construct(
        private Environment $twig,
        private CurrentRequest $current,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(ServerRequestInterface $request, string $template, array $context = []): string
    {
        $previous = $this->current->swap($request);
        try {
            return $this->twig->render($template, $context);
        } finally {
            $this->current->swap($previous);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function response(
        ServerRequestInterface $request,
        string $template,
        array $context = [],
        int $status = 200,
    ): ResponseInterface {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($this->render($request, $template, $context)));
    }
}
