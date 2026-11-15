<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * "Forgot your password?": emails a link for choosing a new password, and sets it.
 *
 * Asking always gives the same answer, so nobody can find out which addresses have an account. Requests are
 * limited per email address and client address (`password_reset` rate limit). Setting a password activates a
 * pending account, makes every earlier link stop working, signs out other sessions and forgets every
 * "keep me signed in" device.
 */
final readonly class PasswordReset
{
    public const string PATH = '/password/reset/';

    public function __construct(
        private Accounts $accounts,
        private PasswordResetTokens $tokens,
        private PasswordHasher $hasher,
        private RememberTokens $rememberTokens,
        private RateLimiters $limiters,
        private MailSender $mail,
        private TemplateRenderer $templates,
        private ClockInterface $clock,
        private Site $site,
    ) {
    }

    /**
     * Sends a link to the address if it has an account that can sign in, or is waiting for its first password.
     *
     * @return positive-int|null seconds to wait when there were too many requests, otherwise null
     */
    public function request(ServerRequestInterface $request, string $email): ?int
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $limit = $this->limiters->limiter(
            'password_reset',
            mb_strtolower(trim($email)) . '|' . (is_string($address) ? $address : 'unknown'),
        )->consume();
        if (!$limit->isAccepted()) {
            return RateLimiters::secondsUntil($limit->getRetryAfter());
        }

        $account = $this->accounts->findAccountByEmail($email);
        if ($account === null || (!$account->isActive() && !$account->isPending())) {
            return null;
        }

        $this->send($request, $account);

        return null;
    }

    /**
     * The link for choosing a password, on the host of the request.
     *
     * @param positive-int $ttl seconds until the link stops working
     */
    public function link(ServerRequestInterface $request, Account $account, int $ttl = PasswordResetTokens::RESET_TTL): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort() === null ? '' : ':' . $uri->getPort();

        return sprintf('%s://%s%s%s%s', $uri->getScheme(), $uri->getHost(), $port, self::PATH, $this->tokens->create($account, $ttl));
    }

    /**
     * Sets the new password (checked against {@see PasswordPolicy} by the caller).
     */
    public function complete(Account $account, #[SensitiveParameter] string $password): void
    {
        $account->setPasswordHash($this->hasher->hash($password), $this->clock->now());
        $this->accounts->saveAccount($account);
        $this->rememberTokens->forgetUser((int) $account->id());
    }

    private function send(ServerRequestInterface $request, Account $account): void
    {
        $pending = $account->isPending();
        $ttl = $pending ? PasswordResetTokens::SETUP_TTL : PasswordResetTokens::RESET_TTL;
        $context = [
            'site_name' => $this->site->name,
            'name' => $account->effectiveName(),
            'link' => $this->link($request, $account, $ttl),
            'pending' => $pending,
            'valid_for' => self::duration($ttl),
        ];

        $this->mail->send(new Email()
            ->to(new Address($account->email(), $account->effectiveName()))
            ->subject(sprintf(
                $pending ? 'Choose your password for %s' : 'Reset your password for %s',
                $this->site->name,
            ))
            ->text($this->templates->render($request, '@auth/email/password.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/password.html.twig', $context)));
    }

    /**
     * "1 hour", "7 days": how long a link works, in words.
     */
    public static function duration(int $seconds): string
    {
        [$amount, $unit] = $seconds >= 86400 && $seconds % 86400 === 0
            ? [intdiv($seconds, 86400), 'day']
            : [max(1, intdiv($seconds, 3600)), 'hour'];

        return sprintf('%d %s%s', $amount, $unit, $amount === 1 ? '' : 's');
    }
}
