<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Container\Services;

/**
 * What is outside the tenant's policies after they were tightened (X-5, stage-4 gap 4): sites with a domain outside
 * `domain_policy` and, per site, how many accounts have an address outside `email_policy`. Nothing is removed and
 * they keep working; `system:check` prints these as notes.
 */
final readonly class PolicyReport
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private TenantConfig $config,
    ) {
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $policies = $this->config->policies;
        $notes = [];
        foreach ($this->sites->all() as $site) {
            if ($site->status === SiteStatus::Deleted) {
                continue;
            }
            $outside = array_values(array_filter($site->domains, static fn (string $domain): bool => $policies->domainProblem($domain) !== null));
            if ($outside !== []) {
                $notes[] = sprintf(
                    'Site "%s" uses %s, outside domain_policy (%s and names under it). It keeps working; new domains must follow the policy.',
                    $site->slug,
                    implode(', ', $outside),
                    $policies->subdomainOf,
                );
            }
            if ($policies->emailDomains === null) {
                continue;
            }
            $count = $this->accountsOutside($site, $policies);
            if ($count > 0) {
                $notes[] = sprintf(
                    'Site "%s" has %d %s with an email address outside email_policy (%s). They keep signing in; new and changed addresses must follow the policy.',
                    $site->slug,
                    $count,
                    $count === 1 ? 'account' : 'accounts',
                    implode(', ', $policies->emailDomains),
                );
            }
        }

        return $notes;
    }

    private function accountsOutside(Site $site, TenantPolicies $policies): int
    {
        try {
            /** @var list<string> $emails */
            $emails = Services::get($this->containers->forSite($site), Connection::class)->fetchFirstColumn('SELECT email FROM users');
        } finally {
            $this->containers->evict($site->slug);
        }

        return count(array_filter($emails, static fn (string $email): bool => $policies->emailProblem($email) !== null));
    }
}
