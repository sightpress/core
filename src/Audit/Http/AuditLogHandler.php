<?php

declare(strict_types=1);

namespace SightPress\Core\Audit\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Audit\AuditEntry;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountDirectory;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/audit`: who changed what, and when, newest first, 50 at a time, in a table with a caption that says what
 * it shows. Filters: who, what kind of thing, and from and to a day (in the site's time zone). `/admin/audit.csv`
 * gives the same entries as a CSV file, all of them. Needs `audit.view`. Nothing here can change the log.
 */
final readonly class AuditLogHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/audit';

    public const string CSV_PATH = '/admin/audit.csv';

    public const string PERMISSION = 'audit.view';

    public const int PER_PAGE = 50;

    /** Most rows one CSV file holds. */
    public const int CSV_LIMIT = 100_000;

    public const array TYPES = [
        'page' => 'Pages',
        'media' => 'Media',
        'menu' => 'Menus',
        'user' => 'People',
        'settings' => 'Site settings',
    ];

    public function __construct(
        private AdminScreens $screens,
        private AuditLog $log,
        private AccountDirectory $accounts,
        private ResponseFactoryInterface $responses,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Actor::fromRequest($request)->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $query = $request->getQueryParams();
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $zone = new DateTimeZone($this->site->defaultTimezone);
        $from = self::day($text('from'), $zone);
        $to = self::day($text('to'), $zone);
        $who = $text('who');
        $type = $text('type');
        $filter = new AuditFilter(
            ctype_digit($who) ? (int) $who : null,
            isset(self::TYPES[$type]) ? $type : null,
            $from,
            $to?->modify('+1 day'),
        );
        $names = $this->names();
        if ($request->getUri()->getPath() === self::CSV_PATH) {
            return $this->csv($this->log->find($filter, self::CSV_LIMIT), $names);
        }

        $total = $this->log->count($filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $text('page')));
        $params = array_filter(['who' => $who, 'type' => $type, 'from' => $from === null ? '' : $text('from'), 'to' => $to === null ? '' : $text('to')], static fn (string $value): bool => $value !== '');

        return $this->screens->render($request, '@audit/log.html.twig', [
            'entries' => array_map(static fn (AuditEntry $entry): array => ['entry' => $entry, 'who' => $entry->actorId === null ? null : ($names[$entry->actorId] ?? sprintf('Account %d', $entry->actorId))], $this->log->find($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE)),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => ['who' => $who, 'type' => $type, 'from' => $params['from'] ?? '', 'to' => $params['to'] ?? ''],
            'filtered' => !$filter->isEmpty(),
            'people' => $names,
            'types' => self::TYPES,
            'query' => http_build_query($params),
            'bad_dates' => ($text('from') !== '' && $from === null) || ($text('to') !== '' && $to === null),
            'timezone' => $this->site->defaultTimezone,
        ]);
    }

    /**
     * Midnight of a `YYYY-MM-DD` day in the zone, in UTC; null when it isn't such a day.
     */
    private static function day(string $text, DateTimeZone $zone): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $zone);

        return $day !== false && $day->format('Y-m-d') === $text ? $day->setTimezone(new DateTimeZone('UTC')) : null;
    }

    /**
     * @return array<int, string> account id => name, for everyone in the log
     */
    private function names(): array
    {
        $names = [];
        foreach ($this->accounts->findAccounts($this->log->actors()) as $account) {
            $names[(int) $account->id()] = $account->effectiveName();
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @param list<AuditEntry>   $entries
     * @param array<int, string> $names
     */
    private function csv(array $entries, array $names): ResponseInterface
    {
        $csv = self::line(['Time (UTC)', 'Who', 'Action', 'Kind', 'Id', 'What happened']);
        foreach ($entries as $entry) {
            $csv .= self::line(array_map(self::cell(...), [
                $entry->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                $entry->actorId === null ? 'System' : ($names[$entry->actorId] ?? sprintf('Account %d', $entry->actorId)),
                $entry->action,
                $entry->entityType,
                $entry->entityId ?? '',
                $entry->summary,
            ]));
        }
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="audit-log-%s.csv"', $this->site->slug))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->getBody()->write($csv);

        return $response;
    }

    /**
     * One CSV line (RFC 4180): a field with a comma, quote, space or line break is quoted, its quotes doubled.
     *
     * @param list<string> $fields
     */
    private static function line(array $fields): string
    {
        return implode(',', array_map(
            static fn (string $field): string => preg_match('/[",\s]/', $field) === 1 ? '"' . str_replace('"', '""', $field) . '"' : $field,
            $fields,
        )) . "\n";
    }

    /**
     * A cell a spreadsheet won't run as a formula.
     */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
