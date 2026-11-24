<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SightPress\Core\Template\Message;

/**
 * Who and what may join the tenant (X-5), from `config/tenant.php`:
 *
 * - `domain_policy`: `'any'` (default) or `['subdomain_of' => 'example.org']`: every site's domains must be
 *   `example.org` or a name under it. Checked by `site:create` and whenever a site's domains change.
 * - `email_policy`: `'any'` (default) or `['domains' => ['example.org', …]]`: only addresses at those exact domains
 *   may be invited, accept an invitation, sign up or become someone's new address. Subdomains match only when
 *   listed.
 *
 * Names are compared normalised ({@see DomainName}). Tightening a policy removes nothing: sites and accounts already
 * outside it keep working, and `system:check` counts them.
 */
final readonly class TenantPolicies
{
    /**
     * @param string|null       $subdomainOf  normalised; null for any domain
     * @param non-empty-list<string>|null $emailDomains normalised; null for any address
     */
    public function __construct(
        public ?string $subdomainOf = null,
        public ?array $emailDomains = null,
    ) {
    }

    /**
     * @param array<mixed> $config what `config/tenant.php` returns
     *
     * @throws TenantConfigException when a policy isn't written as above
     */
    public static function fromArray(array $config): self
    {
        $domain = $config['domain_policy'] ?? 'any';
        $subdomainOf = null;
        if ($domain !== 'any') {
            if (!is_array($domain) || array_keys($domain) !== ['subdomain_of'] || !is_string($domain['subdomain_of'])) {
                throw new TenantConfigException('Tenant setting "domain_policy" must be \'any\' or [\'subdomain_of\' => \'example.org\'].');
            }
            $subdomainOf = self::domain($domain['subdomain_of'], 'domain_policy.subdomain_of');
        }

        $email = $config['email_policy'] ?? 'any';
        $emailDomains = null;
        if ($email !== 'any') {
            if (!is_array($email) || array_keys($email) !== ['domains'] || !is_array($email['domains']) || $email['domains'] === []) {
                throw new TenantConfigException('Tenant setting "email_policy" must be \'any\' or [\'domains\' => [\'example.org\', …]] with at least one domain.');
            }
            $emailDomains = [];
            foreach ($email['domains'] as $listed) {
                $emailDomains[] = self::domain(is_string($listed) ? $listed : '', 'email_policy.domains');
            }
            $emailDomains = array_values(array_unique($emailDomains));
        }

        return new self($subdomainOf, $emailDomains);
    }

    /**
     * Why a site may not use the domain, or null when it may.
     */
    public function domainProblem(string $domain): ?Message
    {
        if ($this->subdomainOf === null || DomainName::isWithin($domain, $this->subdomainOf)) {
            return null;
        }

        return new Message('{domain} can’t be used: sites here must use {allowed} or a name under it, such as www.{allowed}.', ['domain' => DomainName::normalise($domain), 'allowed' => $this->subdomainOf]);
    }

    /**
     * Why the address may not join, or null when it may.
     */
    public function emailProblem(string $email): ?Message
    {
        if ($this->emailDomains === null || in_array(DomainName::ofEmail($email), $this->emailDomains, true)) {
            return null;
        }

        return new Message('Only email addresses at {domains} can join this site. Use an address there.', ['domains' => Message::list($this->emailDomains)]);
    }

    private static function domain(string $value, string $name): string
    {
        $domain = DomainName::normalise($value);
        if (preg_match(Site::DOMAIN_PATTERN, $domain) !== 1) {
            throw new TenantConfigException(sprintf('Tenant setting "%s" has "%s", which is not a domain such as example.org.', $name, $value));
        }

        return $domain;
    }
}
