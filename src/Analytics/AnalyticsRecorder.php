<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Analytics\Classification\BotClassifier;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Analytics\Classification\SourceClassifier;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Tenant\Site;

/**
 * Counts what a request to the public site showed, after the response was sent (ADR-0012): a page read by a person
 * (with the day's visitor key, the source, the language and the device), an address that doesn't exist, a page or
 * file read by a bot, and the searches made on the way ({@see PendingSearches}).
 *
 * Never counted: anything but `GET` (so no `HEAD`), prefetches and prerenders, requests with `Sec-GPC: 1` or
 * `DNT: 1`, people signed in to the site, the admin, assets and media files. Bots never count as visitors. Nothing
 * of the IP address, the user agent or the query string is stored: only the visitor key made from them with the
 * day's salt, and the names and labels the classifiers give.
 */
final readonly class AnalyticsRecorder
{
    /** Paths that are never counted, not even as "not found": the admin, assets, media and `/_…` addresses. */
    public const string IGNORED = '#^/(admin|assets|media)(/|$)|^/_#';

    /** File endings of assets and of what vulnerability scanners try, which are never "pages not found". */
    private const string ASSET = '/\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|ogg|pdf|zip|json|xml|txt|php|asp|aspx|env|ini|bak|sql)$/i';

    public function __construct(
        private AnalyticsStore $store,
        private BotClassifier $bots,
        private DeviceClassifier $devices,
        private SourceClassifier $sources,
        private PendingSearches $pending,
        private Site $site,
    ) {
    }

    public function __invoke(RequestAnswered $event): void
    {
        $searches = $this->pending->take();
        $request = $event->request;
        $path = $request->getUri()->getPath();
        if (!$this->counts($event, $path)) {
            return;
        }
        $at = $event->at;
        $day = $at->setTimezone(new DateTimeZone($this->site->defaultTimezone))->format('Y-m-d');
        $resource = $event->resource;
        $kind = $resource->kind();
        $bot = $this->bots->classify($request->getHeaderLine('User-Agent'));
        if ($bot !== null) {
            if ($event->status === 200 && $kind !== null) {
                $this->store->record(new Hit($day, $at, Hit::BOT, $kind, $resource->pageId(), $resource->locale(), bot: $bot->name, botKind: $bot->kind));
            }

            return;
        }
        if ($event->status === 404) {
            if (preg_match(self::ASSET, $path) !== 1) {
                $this->store->record(new Hit($day, $at, Hit::NOT_FOUND, path: mb_substr(rawurldecode($path), 0, Hit::PATH_LENGTH)));
            }

            return;
        }
        if ($event->status !== 200) {
            return;
        }
        if ($kind === AnsweredResource::PAGE || $kind === AnsweredResource::MARKDOWN) {
            $this->store->record(new Hit(
                $day,
                $at,
                Hit::VIEW,
                $kind,
                $resource->pageId(),
                $resource->locale(),
                visitor: $this->visitor($request, $day),
                source: $this->sources->classify($request, $this->site->domains),
                device: $this->devices->classify($request),
            ));
        }
        foreach ($searches as $search) {
            $this->store->record(new Hit($day, $at, Hit::SEARCH, locale: $search['locale'], query: $search['query'], results: $search['results']));
        }
    }

    private function counts(RequestAnswered $event, string $path): bool
    {
        $request = $event->request;
        if ($request->getMethod() !== 'GET' || $event->signedIn) {
            return false;
        }
        foreach (['Sec-GPC', 'DNT'] as $header) {
            if (trim($request->getHeaderLine($header)) === '1') {
                return false;
            }
        }
        $purpose = strtolower($request->getHeaderLine('Sec-Purpose') . ' ' . $request->getHeaderLine('Purpose') . ' ' . $request->getHeaderLine('X-Purpose') . ' ' . $request->getHeaderLine('X-Moz'));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender') || str_contains($purpose, 'preview')) {
            return false;
        }
        return preg_match(self::IGNORED, $path) !== 1;
    }

    /**
     * The visitor's key for the day: an HMAC of the site, the IP address and the user agent with the day's salt,
     * shortened. The same person on the same day gets the same key; on another day, or once the salt is deleted, it
     * can't be linked to them or to their other days.
     */
    private function visitor(ServerRequestInterface $request, string $day): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return substr(hash_hmac('sha256', implode("\n", [
            $this->site->id,
            is_string($address) ? $address : '',
            $request->getHeaderLine('User-Agent'),
        ]), $this->store->salt($day)), 0, 32);
    }
}
