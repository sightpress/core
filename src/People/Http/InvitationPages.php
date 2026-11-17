<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;

/**
 * Renders the pages of accepting an invitation in the site's theme, with any waiting flash messages. Never cached,
 * never indexed, and they send no referrer.
 */
final readonly class InvitationPages
{
    public function __construct(
        private TemplateRenderer $templates,
        private Site $site,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function page(ServerRequestInterface $request, string $template, array $context, int $status = 200): ResponseInterface
    {
        return $this->templates
            ->response($request, $template, $context + [
                'site_name' => $this->site->name,
                'messages' => Flash::take(Session::fromRequest($request)),
            ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
