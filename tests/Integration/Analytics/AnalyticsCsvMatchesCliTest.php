<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Analytics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Command\AnalyticsReportCommand;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * AN-3 acceptance: every CSV download of the Analytics screen has the same values as `site:analytics:report
 * --format=json` for the same range.
 */
#[CoversNothing]
final class AnalyticsCsvMatchesCliTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    public function testTheCsvDownloadsMatchTheCommandLine(): void
    {
        $site = new KernelSite('analytics_csv', locales: ['en-GB', 'pt-PT']);
        try {
            $container = $site->container();
            $store = Services::get($container, AnalyticsStore::class);
            foreach (['2026-11-01' => 3, '2026-11-02' => 5] as $day => $n) {
                $store->finishDay($day, [
                    ['metric' => 'visitors', 'dimension' => '', 'item' => '', 'value' => $n],
                    ['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 2 * $n],
                    ['metric' => 'views', 'dimension' => 'page', 'item' => '7:en-GB', 'value' => $n],
                    ['metric' => 'visitors', 'dimension' => 'source', 'item' => 'site:example.net', 'value' => $n],
                    ['metric' => 'not_found', 'dimension' => 'path', 'item' => '=SUM(A1)', 'value' => 1],
                    ['metric' => 'bot_reads', 'dimension' => 'bot', 'item' => 'Googlebot|search|page', 'value' => $n],
                    ['metric' => 'searches', 'dimension' => 'query', 'item' => 'parking, "free"', 'value' => $n],
                ]);
            }
            $user = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
            Services::get($container, UserRepository::class)->save($user);
            $roles = Services::get($container, RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole('admin') ?? self::fail('admin'));
            $browser = new Browser($site->kernel, 'https://example.org');
            self::assertSame(303, $browser->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD])->getStatusCode());

            $tenant = $site->kernel->container();
            $output = new RecordingOutput();
            new AnalyticsReportCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class))
                ->run(new ArrayCommandInput(['slug' => 'demo'], ['from' => '2026-10-31', 'to' => '2026-11-02', 'report' => 'all', 'format' => 'json']), $output);
            $json = json_decode($output->lines[0], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($json);
            self::assertIsArray($json['reports']);

            foreach (AnalyticsReport::TABLES as $name) {
                $csv = (string) $browser->request('GET', '/admin/analytics/download?report=' . $name . '&from=2026-10-31&to=2026-11-02')->getBody();
                $lines = array_map(static fn (string $line): array => str_getcsv($line, escape: ''), explode("\r\n", rtrim($csv, "\r\n")));
                array_shift($lines);
                self::assertIsArray($json['reports'][$name]);
                $expected = array_map(
                    static fn (mixed $row): array => !is_array($row) ? [] : array_map(static fn (mixed $value): string => $value === null ? '' : (is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : (is_scalar($value) ? (string) $value : '')), array_values($row)),
                    $json['reports'][$name],
                );
                self::assertSame($expected, $lines === [['']] ? [] : $lines, $name);
            }
        } finally {
            $site->remove();
        }
    }
}
