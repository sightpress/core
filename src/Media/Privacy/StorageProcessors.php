<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Privacy;

use SightPress\Core\Infrastructure\Http\OutboundHostSource;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * Where the site's files are kept and served from, while it uses cloud storage or a CDN (M-1, M-2, D50, ADR-0075):
 * Amazon S3, Cloudflare R2, or the CDN's host. A storage server at the operator's own address (such as MinIO) isn't a
 * third party; it is only allowed as an outgoing address, for the upload check.
 */
final readonly class StorageProcessors implements ProcessorSource, OutboundHostSource
{
    /** AWS regions and the country their data centres are in; others show as "not known". */
    private const array AWS_COUNTRIES = [
        'eu-west-1' => 'IE', 'eu-west-2' => 'GB', 'eu-west-3' => 'FR', 'eu-central-1' => 'DE', 'eu-central-2' => 'CH',
        'eu-north-1' => 'SE', 'eu-south-1' => 'IT', 'eu-south-2' => 'ES', 'us-east-1' => 'US', 'us-east-2' => 'US',
        'us-west-1' => 'US', 'us-west-2' => 'US', 'ca-central-1' => 'CA', 'sa-east-1' => 'BR', 'il-central-1' => 'IL',
        'ap-southeast-2' => 'AU', 'ap-northeast-1' => 'JP',
    ];

    private const string UNKNOWN_COUNTRY = 'ZZ';

    public function __construct(private Site $site)
    {
    }

    public function processors(): iterable
    {
        $processors = [];
        $s3 = $this->site->storage->s3;
        $stored = new Message('The files added to the media library and the profile pictures of accounts, to keep them and deliver them to visitors.');
        if ($s3 !== null && $this->site->storage->adapter === 's3') {
            $processor = self::bucket($s3, $stored);
            if ($processor !== null) {
                $processors[] = $processor;
            }
        }
        $cdn = $this->site->storage->cdnUrl;
        if ($cdn !== null) {
            $processors[] = new ProcessorDeclaration(
                'media.cdn',
                (string) parse_url($cdn, PHP_URL_HOST),
                new Message('The internet address and browser details of visitors, as they download the site’s files, pictures and styles from it.'),
                [self::UNKNOWN_COUNTRY],
            );
        }

        return $processors;
    }

    public function outboundHosts(): iterable
    {
        $s3 = $this->site->storage->s3;
        if ($s3 === null || $this->site->storage->adapter !== 's3') {
            return [];
        }
        $host = strtolower((string) parse_url($s3->uploadOrigin(), PHP_URL_HOST));

        return $host === '' ? [] : [$host];
    }

    private static function bucket(SiteS3Storage $s3, Message $stored): ?ProcessorDeclaration
    {
        if ($s3->endpoint === null) {
            return new ProcessorDeclaration(
                'media.storage.aws',
                'Amazon Web Services (Amazon S3)',
                $stored,
                [self::AWS_COUNTRIES[$s3->region] ?? self::UNKNOWN_COUNTRY],
                privacyPolicyUrl: 'https://aws.amazon.com/privacy/',
            );
        }
        $host = strtolower((string) parse_url($s3->endpoint, PHP_URL_HOST));
        if (str_ends_with($host, '.r2.cloudflarestorage.com')) {
            return new ProcessorDeclaration(
                'media.storage.cloudflare',
                'Cloudflare R2',
                $stored,
                [str_contains($host, '.eu.r2.') ? 'EU' : self::UNKNOWN_COUNTRY],
                privacyPolicyUrl: 'https://www.cloudflare.com/privacypolicy/',
            );
        }
        // Another S3 service with a public name; a server on the operator's network isn't a third party.
        return str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false
            ? new ProcessorDeclaration('media.storage.s3', $host, $stored, [self::UNKNOWN_COUNTRY])
            : null;
    }
}
