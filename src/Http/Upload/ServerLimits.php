<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use SightPress\Core\Tenant\TenantConfig;

/**
 * What the server lets one request carry, read from PHP's settings and the web server's body limit, which PHP can't
 * read, so the operator states it in `config/tenant.php` (`uploads.proxy_body_limit`).
 *
 * Sizes are in bytes; null means "no limit" (or, for the web server and free space, "not known").
 */
final readonly class ServerLimits
{
    /**
     * Room in a request for the form's other fields and the multipart boundaries, besides the file itself.
     */
    public const int REQUEST_OVERHEAD = 1024 * 1024;

    /**
     * @param int|null $uploadMaxFilesize `upload_max_filesize`
     * @param int|null $postMaxSize       `post_max_size`
     * @param int|null $memoryLimit       `memory_limit`
     * @param int      $maxFileUploads    `max_file_uploads`
     * @param int      $maxInputVars      `max_input_vars`
     * @param int|null $proxyBodyLimit    the web server's body limit (nginx `client_max_body_size` …)
     * @param string   $tempDir           where PHP keeps uploads while they arrive
     * @param bool     $tempWritable      whether that folder exists and PHP can write to it
     * @param int|null $tempFree          free space in that folder
     */
    public function __construct(
        public ?int $uploadMaxFilesize,
        public ?int $postMaxSize,
        public ?int $memoryLimit,
        public int $maxFileUploads,
        public int $maxInputVars,
        public ?int $proxyBodyLimit,
        public string $tempDir,
        public bool $tempWritable,
        public ?int $tempFree,
    ) {
    }

    /**
     * The limits of the PHP that runs this code.
     */
    public static function current(TenantConfig $config): self
    {
        $tempDir = (string) ini_get('upload_tmp_dir');
        $tempDir = $tempDir === '' ? sys_get_temp_dir() : $tempDir;
        $writable = is_dir($tempDir) && is_writable($tempDir);
        $free = $writable ? @disk_free_space($tempDir) : false;

        return new self(
            IniSize::bytes(ini_get('upload_max_filesize')),
            IniSize::bytes(ini_get('post_max_size')),
            IniSize::bytes(ini_get('memory_limit')),
            (int) ini_get('max_file_uploads'),
            (int) ini_get('max_input_vars'),
            $config->uploadProxyBodyLimit,
            $tempDir,
            $writable,
            $free === false ? null : (int) $free,
        );
    }

    /**
     * The largest file one browser request can carry: the smallest of `upload_max_filesize`, `post_max_size` and
     * the web server's limit, each less the {@see REQUEST_OVERHEAD} for the rest of the form. Null: no limit.
     */
    public function requestLimit(): ?int
    {
        $limits = array_filter([
            $this->uploadMaxFilesize,
            $this->postMaxSize === null ? null : $this->postMaxSize - self::REQUEST_OVERHEAD,
            $this->proxyBodyLimit === null ? null : $this->proxyBodyLimit - self::REQUEST_OVERHEAD,
        ], is_int(...));

        return $limits === [] ? null : max(1, min($limits));
    }

    /**
     * An app limit, lowered to what one request can carry.
     *
     * @param positive-int $appLimit
     *
     * @return positive-int
     */
    public function cap(int $appLimit): int
    {
        $request = $this->requestLimit();

        return $request === null ? $appLimit : max(1, min($appLimit, $request));
    }

    /**
     * Whether a request body of this many bytes is larger than PHP reads (`post_max_size`): PHP then drops every
     * field and file, the CSRF token too.
     */
    public function overflows(int $contentLength): bool
    {
        return $this->postMaxSize !== null && $contentLength > $this->postMaxSize;
    }
}
