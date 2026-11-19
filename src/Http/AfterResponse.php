<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Work that waits until the response has been sent: {@see Kernel::run()} sends the response, lets the visitor go
 * (`fastcgi_finish_request()` where the server has it) and then calls {@see run()}. A failure is logged with the
 * error's class and message, and the rest of the work still runs; it can never change the response.
 *
 * @see \SightPress\Core\Kernel::terminate()
 */
final class AfterResponse
{
    /** @var list<Closure(): void> */
    private array $work = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param Closure(): void $work
     */
    public function add(Closure $work): void
    {
        $this->work[] = $work;
    }

    /**
     * @param Closure(): mixed|null $release ends the response for the client before the work starts
     *                                       (`fastcgi_finish_request`)
     */
    public function run(?Closure $release = null): void
    {
        if ($release !== null && $this->work !== []) {
            $release();
        }
        $work = $this->work;
        $this->work = [];
        foreach ($work as $item) {
            try {
                $item();
            } catch (Throwable $exception) {
                $this->logger->error('Work after the response failed: {class}: {message}', [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
