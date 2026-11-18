<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Contract;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Template\Message;

/**
 * Renders admin screens inside the admin shell, for every module with screens under `/admin`.
 *
 * Templates extend `@admin/layout.html.twig` and fill its `title` and `content` blocks (`content` starts with the
 * screen's `<h1>`). The shell adds the navigation the person may use, their name, the site name and the flash
 * messages; the `<title>` names the screen and, when the context has a `form` with errors (or an `error_count`),
 * how many errors there are. Responses are never cached or indexed.
 */
interface AdminScreens
{
    /**
     * @param array<string, mixed> $context
     */
    public function render(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface;

    /**
     * A 303 to an admin screen (Post/Redirect/Get), with an optional message for the next page's live region.
     */
    public function redirect(ServerRequestInterface $request, string $path, string|Message|null $message = null): ResponseInterface;
}
