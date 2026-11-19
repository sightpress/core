<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Core\Template\Message;

/**
 * Finds server settings that stop uploads or saves from working, and says in plain words which setting to change
 * and to what. Admins with `settings.manage` see the {@see problems()} on the dashboard and in the media library;
 * operators see them, and the {@see notes()}, with `bin/sightpress system:check`.
 *
 * Pictures and documents must fit in one request. Larger audio files and videos will be sent in parts (chunked
 * uploads, a later version); until then the server's limit caps them, which is a note, not a problem.
 */
final readonly class ServerLimitsCheck
{
    public const int RECOMMENDED_INPUT_VARS = 5000;

    /**
     * Bytes of `memory_limit` needed per pixel of the largest picture: decoding takes about 4 bytes a pixel, and
     * resizing needs room for a second copy and PHP itself.
     */
    private const int BYTES_PER_PIXEL = 10;

    private const int MEMORY_STEP = 64 * 1024 * 1024;

    private const int MIN_TEMP_FREE = 64 * 1024 * 1024;

    public function __construct(
        private MediaLimits $limits,
        private ServerLimits $server,
    ) {
    }

    /**
     * Settings that stop uploads or saves from working as the site's limits promise.
     *
     * @return list<Message>
     */
    public function problems(): array
    {
        $server = $this->server;
        $problems = [];

        $needed = max($this->limits->maxBytes(MediaKind::Image), $this->limits->maxBytes(MediaKind::Document));
        $request = $server->requestLimit();
        if ($request !== null && $request < $needed) {
            $problems[] = new Message(
                'Files larger than {limit} can’t be uploaded because of your server’s settings, although the site accepts pictures up to {image} and documents up to {document}. Ask your hosting provider to raise {settings}.',
                [
                    'limit' => MediaLimits::describe($request),
                    'image' => MediaLimits::describe($this->limits->maxBytes(MediaKind::Image)),
                    'document' => MediaLimits::describe($this->limits->maxBytes(MediaKind::Document)),
                    'settings' => $this->raise($needed),
                ],
            );
        }

        if ($server->uploadMaxFilesize !== null && $server->postMaxSize !== null && $server->postMaxSize < $server->uploadMaxFilesize) {
            $problems[] = new Message(
                'The server setting post_max_size ({post}) is smaller than upload_max_filesize ({upload}), so large files fail with a confusing error. Set post_max_size to at least {needed}.',
                ['post' => MediaLimits::describe($server->postMaxSize), 'upload' => MediaLimits::describe($server->uploadMaxFilesize), 'needed' => IniSize::shorthand($server->uploadMaxFilesize + ServerLimits::REQUEST_OVERHEAD)],
            );
        }

        if ($server->proxyBodyLimit !== null && $server->uploadMaxFilesize !== null && $server->proxyBodyLimit < $server->uploadMaxFilesize + ServerLimits::REQUEST_OVERHEAD) {
            $problems[] = new Message(
                'The web server accepts at most {proxy} per request, less than PHP’s upload_max_filesize ({upload}) allows. Raise the web server’s limit (for nginx, client_max_body_size) to at least {needed}, and set uploads.proxy_body_limit in config/tenant.php to match.',
                ['proxy' => MediaLimits::describe($server->proxyBodyLimit), 'upload' => MediaLimits::describe($server->uploadMaxFilesize), 'needed' => IniSize::shorthand($server->uploadMaxFilesize + ServerLimits::REQUEST_OVERHEAD)],
            );
        }

        $memory = $this->memoryNeeded();
        if ($server->memoryLimit !== null && $server->memoryLimit < $memory) {
            $problems[] = new Message(
                'The server setting memory_limit ({memory}) is too low to resize pictures of up to {pixels} million pixels. Raise it to at least {needed}.',
                ['memory' => MediaLimits::describe($server->memoryLimit), 'pixels' => (string) round($this->limits->imagePixels / 1_000_000, 1), 'needed' => IniSize::shorthand($memory)],
            );
        }

        if ($server->maxInputVars < self::RECOMMENDED_INPUT_VARS) {
            $problems[] = new Message(
                'The server setting max_input_vars is {vars}, so saving a long page in the block editor can lose some of its content. Raise it to at least {needed}.',
                ['vars' => (string) $server->maxInputVars, 'needed' => (string) self::RECOMMENDED_INPUT_VARS],
            );
        }

        if (!$server->tempWritable) {
            $problems[] = new Message(
                'The folder for files being uploaded ({folder}) is missing or PHP can’t write to it, so no file can be uploaded. Set upload_tmp_dir to a folder PHP can write to.',
                ['folder' => $server->tempDir],
            );
        } elseif ($server->tempFree !== null && $server->tempFree < max(self::MIN_TEMP_FREE, $request ?? 0)) {
            $problems[] = new Message(
                'The folder for files being uploaded ({folder}) has only {free} free, so large files can fail. Free some space, or set upload_tmp_dir to a folder with more room.',
                ['folder' => $server->tempDir, 'free' => MediaLimits::describe($server->tempFree)],
            );
        }

        return $problems;
    }

    /**
     * Things worth knowing that don't stop anything working.
     *
     * @return list<Message>
     */
    public function notes(): array
    {
        $notes = [];
        $allowance = new UploadAllowance($this->limits, $this->server);
        foreach ([MediaKind::Audio, MediaKind::Video] as $kind) {
            if ($allowance->limitedByServer($kind)) {
                $notes[] = new Message(
                    'The site accepts {kind} up to {limit}, but the server’s settings limit them to {max}. Larger ones will be possible once files can be sent in parts.',
                    ['kind' => UploadAllowance::plural($kind), 'limit' => MediaLimits::describe($this->limits->maxBytes($kind)), 'max' => MediaLimits::describe($allowance->maxBytes($kind))],
                );
            }
        }
        if ($this->server->proxyBodyLimit === null) {
            $notes[] = new Message('The web server’s request body limit isn’t stated (uploads.proxy_body_limit in config/tenant.php), so it couldn’t be checked.');
        }

        return $notes;
    }

    /**
     * "upload_max_filesize to 50M and post_max_size to 51M": the settings that are too low for files of this size.
     */
    private function raise(int $bytes): Message
    {
        $server = $this->server;
        $settings = [];
        if ($server->uploadMaxFilesize !== null && $server->uploadMaxFilesize < $bytes) {
            $settings[] = self::setting('upload_max_filesize', IniSize::shorthand($bytes));
        }
        $body = IniSize::shorthand($bytes + ServerLimits::REQUEST_OVERHEAD);
        if ($server->postMaxSize !== null && $server->postMaxSize < $bytes + ServerLimits::REQUEST_OVERHEAD) {
            $settings[] = self::setting('post_max_size', $body);
        }
        if ($server->proxyBodyLimit !== null && $server->proxyBodyLimit < $bytes + ServerLimits::REQUEST_OVERHEAD) {
            $settings[] = self::setting(new Message('the web server’s limit (for nginx, client_max_body_size)'), $body);
        }

        return Message::list($settings === [] ? [self::setting('upload_max_filesize', IniSize::shorthand($bytes))] : $settings);
    }

    private static function setting(string|Message $name, string $value): Message
    {
        return new Message('{setting} to {value}', ['setting' => $name, 'value' => $value]);
    }

    /**
     * @return positive-int
     */
    private function memoryNeeded(): int
    {
        return max(1, (int) ceil($this->limits->imagePixels * self::BYTES_PER_PIXEL / self::MEMORY_STEP)) * self::MEMORY_STEP;
    }
}
