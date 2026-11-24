<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\DomainsEmailPolicy;
use SightPress\Core\Tenant\DomainName;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantPolicies;

#[CoversClass(TenantPolicies::class)]
#[CoversClass(DomainsEmailPolicy::class)]
#[UsesClass(DomainName::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Media')]
final class TenantPoliciesTest extends TestCase
{
    public function testEverythingIsAllowedByDefault(): void
    {
        $policies = TenantConfig::fromArray([], '/project')->policies;

        self::assertNull($policies->subdomainOf);
        self::assertNull($policies->emailDomains);
        self::assertNull($policies->domainProblem('anything.test'));
        self::assertNull($policies->emailProblem('ada@anywhere.test'));
        self::assertEquals(new TenantPolicies(), TenantPolicies::fromArray(['domain_policy' => 'any', 'email_policy' => 'any']));
    }

    public function testReadsAndNormalisesThePolicies(): void
    {
        $policies = TenantPolicies::fromArray([
            'domain_policy' => ['subdomain_of' => 'Example.ORG.'],
            'email_policy' => ['domains' => ['Example.org', 'BÜCHER.example', 'example.org']],
        ]);

        self::assertSame('example.org', $policies->subdomainOf);
        self::assertSame(['example.org', 'xn--bcher-kva.example'], $policies->emailDomains);
    }

    public function testDomainRegistrationUsesTheEmailDomainsOrSomeOfThem(): void
    {
        self::assertNull(TenantPolicies::fromArray(['domain_registration' => 'off'])->registrationDomains);
        self::assertSame(['example.org', 'example.com'], TenantPolicies::fromArray(['email_policy' => ['domains' => ['example.org', 'example.com']], 'domain_registration' => 'on'])->registrationDomains);
        self::assertSame(['example.com'], TenantPolicies::fromArray(['email_policy' => ['domains' => ['example.org', 'example.com']], 'domain_registration' => ['domains' => ['EXAMPLE.com', 'example.com.']]])->registrationDomains);
        self::assertSame(['xn--bcher-kva.example'], TenantPolicies::fromArray(['domain_registration' => ['domains' => ['bücher.example']]])->registrationDomains, 'any domain while email_policy is any');
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $domain = 'Tenant setting "domain_policy" must be \'any\' or [\'subdomain_of\' => \'example.org\'].';
        $email = 'Tenant setting "email_policy" must be \'any\' or [\'domains\' => [\'example.org\', …]] with at least one domain.';
        yield 'a domain policy that is a word' => [['domain_policy' => 'strict'], $domain];
        yield 'a domain policy with other keys' => [['domain_policy' => ['subdomain_of' => 'example.org', 'also' => 1]], $domain];
        yield 'a domain policy that isn\'t text' => [['domain_policy' => ['subdomain_of' => 5]], $domain];
        yield 'a domain policy that isn\'t a domain' => [['domain_policy' => ['subdomain_of' => 'not a domain']], 'Tenant setting "domain_policy.subdomain_of" has "not a domain", which is not a domain such as example.org.'];
        yield 'an email policy that is a word' => [['email_policy' => 'domains'], $email];
        yield 'an email policy without domains' => [['email_policy' => ['domains' => []]], $email];
        yield 'an email policy with a list that isn\'t one' => [['email_policy' => ['domains' => 'example.org']], $email];
        yield 'an email policy with a domain that isn\'t text' => [['email_policy' => ['domains' => [7]]], 'Tenant setting "email_policy.domains" has "", which is not a domain such as example.org.'];
        yield 'domain registration on without email domains' => [['domain_registration' => 'on'], 'Tenant setting "domain_registration" is \'on\', which uses the domains of "email_policy", but "email_policy" is \'any\'.'];
        yield 'domain registration that is a word' => [['domain_registration' => 'yes'], 'Tenant setting "domain_registration" must be \'off\', \'on\' or [\'domains\' => [\'example.org\', …]] with at least one domain.'];
        yield 'domain registration without domains' => [['domain_registration' => ['domains' => []]], 'Tenant setting "domain_registration" must be \'off\', \'on\' or'];
        yield 'domain registration with a domain that isn\'t text' => [['domain_registration' => ['domains' => [false]]], 'Tenant setting "domain_registration.domains" has "", which is not a domain such as example.org.'];
        yield 'domain registration outside the email domains' => [['email_policy' => ['domains' => ['example.org']], 'domain_registration' => ['domains' => ['mail.example.org']]], 'Tenant setting "domain_registration.domains" has "mail.example.org", which is not one of the "email_policy" domains.'];
        yield 'an email policy with an address' => [['email_policy' => ['domains' => ['@example.org']]], 'Tenant setting "email_policy.domains" has "@example.org", which is not a domain such as example.org.'];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalid')]
    public function testAPolicyThatIsntWrittenRightStopsTheBoot(array $config, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);

        TenantConfig::fromArray($config, '/project');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function domains(): iterable
    {
        yield 'the domain itself' => ['example.org', true];
        yield 'a name under it' => ['news.example.org', true];
        yield 'upper case' => ['NEWS.Example.org', true];
        yield 'a longer name ending the same' => ['evil-example.org', false];
        yield 'the domain inside another' => ['example.org.evil.test', false];
    }

    #[DataProvider('domains')]
    public function testSitesMustUseTheDomainOrANameUnderIt(string $domain, bool $allowed): void
    {
        $problem = TenantPolicies::fromArray(['domain_policy' => ['subdomain_of' => 'example.org']])->domainProblem($domain);

        self::assertSame($allowed ? null : sprintf('%s can’t be used: sites here must use example.org or a name under it, such as www.example.org.', strtolower($domain)), $problem === null ? null : (string) $problem);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'a listed domain' => ['ada@example.org', true];
        yield 'upper case' => ['Ada@EXAMPLE.ORG', true];
        yield 'an international domain' => ['ana@bücher.example', true];
        yield 'the same in ASCII' => ['ana@xn--bcher-kva.example', true];
        yield 'a subdomain that isn\'t listed' => ['ada@mail.example.org', false];
        yield 'a longer name ending the same' => ['ada@evil-example.org', false];
        yield 'the domain inside another' => ['ada@example.org.evil.test', false];
        yield 'the domain before the last at' => ['ada@example.org@evil.test', false];
        yield 'no domain' => ['ada', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyAddressesAtTheListedDomainsMayJoin(string $email, bool $allowed): void
    {
        $policy = new DomainsEmailPolicy(TenantPolicies::fromArray(['email_policy' => ['domains' => ['example.org', 'bücher.example']]]));
        $problem = $policy->problem($email);

        self::assertSame($allowed ? null : 'Only email addresses at example.org and xn--bcher-kva.example can join this site. Use an address there.', $problem === null ? null : (string) $problem);
    }
}
