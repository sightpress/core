<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * The AWS SDK's `handler`: answers each operation from `$answers` (an array result, or an error as
 * `['error' => code, 'status' => n]`), or with an empty result, and records every call.
 */
final class FakeAws
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<array-key, mixed>|list<array<array-key, mixed>>> */
    public array $answers = [];

    public function __invoke(CommandInterface $command, ?RequestInterface $request = null): PromiseInterface
    {
        $name = $command->getName();
        $this->calls[] = [$name, $command->toArray()];
        $answer = $this->answers[$name] ?? [];
        if (array_is_list($answer) && $answer !== []) {
            $next = array_shift($answer);
            $this->answers[$name] = $answer;
            $answer = is_array($next) ? $next : [];
        }
        /** @var array<string, mixed> $answer */
        if (isset($answer['error']) || isset($answer['status'])) {
            return Create::rejectionFor(new AwsException('Key AKIASECRETVALUE refused', $command, [
                'code' => $answer['error'] ?? null,
                'response' => new Response(is_int($answer['status'] ?? null) ? $answer['status'] : 400),
            ]));
        }

        return Create::promiseFor(new Result($answer));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function last(string $operation): array
    {
        foreach (array_reverse($this->calls) as [$name, $arguments]) {
            if ($name === $operation) {
                return $arguments;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }

    /**
     * A value deep inside an answer or a recorded call.
     */
    public static function dig(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            $data = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function items(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(mixed $data): array
    {
        $decoded = json_decode(is_string($data) ? $data : '', true);

        return is_array($decoded) ? $decoded : [];
    }
}
