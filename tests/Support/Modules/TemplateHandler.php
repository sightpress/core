<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Template\TemplateRenderer;

/**
 * Renders a test module's template.
 */
final readonly class TemplateHandler implements RequestHandlerInterface
{
    public function __construct(private TemplateRenderer $templates)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->templates->response($request, '@acme/hello.html.twig', [
            'name' => '<Ada>',
            'when' => new DateTimeImmutable('2026-11-14 12:00:00 UTC'),
        ]);
    }
}
