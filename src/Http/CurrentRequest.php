<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The request a template is being rendered for, so template functions (`csp_nonce()`, `can()` …) can read it even
 * inside macros, which don't see the template's variables.
 */
final class CurrentRequest
{
    private ?ServerRequestInterface $request = null;

    public function get(): ServerRequestInterface
    {
        return $this->request ?? throw new LogicException('No request is being handled: render templates through the TemplateRenderer.');
    }

    /**
     * Sets the current request and returns the one it replaces.
     */
    public function swap(?ServerRequestInterface $request): ?ServerRequestInterface
    {
        $previous = $this->request;
        $this->request = $request;

        return $previous;
    }
}
