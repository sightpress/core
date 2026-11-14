<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use SightPress\Core\Http\Csrf\CsrfTokenMismatchException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Throwable;

/**
 * Turns an exception into the {@see ErrorPage} people see: plain en-GB words that say what happened and what to do.
 *
 * Any exception that isn't an HTTP error is a 500; its message is never shown here (details only appear in debug
 * mode, see {@see ErrorResponder}).
 */
final readonly class ErrorCatalogue
{
    public function pageFor(Throwable $exception): ErrorPage
    {
        if ($exception instanceof NoSiteConfiguredException) {
            return new ErrorPage(404, 'No site configured', [
                'SightPress is running, but no site is set up for this address yet.',
                'If you look after this server, create a site and link this address to it.',
            ], homeLink: false);
        }

        if ($exception instanceof CsrfTokenMismatchException) {
            return new ErrorPage(403, 'This form has expired', [
                'For your security, a form only works for a while after you open it, and only on this website.',
                'Go back, reload the page and send the form again.',
            ]);
        }

        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $headers = [];
        $retryAfter = $exception instanceof RetryAfter ? $exception->retryAfter() : null;
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }
        if ($exception instanceof HttpMethodNotAllowedException && $exception->getAllowedMethods() !== []) {
            $headers['Allow'] = implode(', ', $exception->getAllowedMethods());
        }

        return match ($status) {
            401 => new ErrorPage(401, 'Sign in to see this page', [
                'You need to sign in before you can see this page.',
            ], $headers),
            403 => new ErrorPage(403, 'You do not have permission to see this page', [
                'If you think you should have access, ask the person who looks after this website.',
            ], $headers),
            404 => new ErrorPage(404, 'Page not found', [
                'If you typed the web address, check it is correct.',
                'If you pasted the web address, check you copied the entire address.',
            ], $headers),
            405 => new ErrorPage(405, 'This page cannot do that', [
                'The page does not accept this kind of request. Go back and try again.',
            ], $headers),
            410 => new ErrorPage(410, 'This page has been removed', [
                'The page you are looking for is no longer available.',
            ], $headers),
            429 => new ErrorPage(429, 'Too many requests', [
                'You have sent too many requests in a short time.',
                $this->tryAgain($retryAfter),
            ], $headers),
            503 => new ErrorPage(503, 'Sorry, the service is unavailable', [
                'This website is not available at the moment.',
                $this->tryAgain($retryAfter),
            ], $headers),
            default => $status >= 500
                ? new ErrorPage($status, 'Sorry, there is a problem with the service', [
                    'Something went wrong on our side. Try again later.',
                ], $headers)
                : new ErrorPage($status, 'Sorry, something is wrong with this request', [
                    'The website could not understand the request. Go back, check what you entered and try again.',
                ], $headers),
        };
    }

    /**
     * @return non-empty-string
     */
    private function tryAgain(?int $seconds): string
    {
        if ($seconds === null) {
            return 'Try again later.';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes <= 1 ? 'Try again in a minute.' : sprintf('Try again in %d minutes.', $minutes);
    }
}
