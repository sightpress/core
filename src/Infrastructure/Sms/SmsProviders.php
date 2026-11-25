<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Sms;

use LogicException;
use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Sms\SmsProvider;

/**
 * The text message providers a site may use (N-2): core's own, which the operator can set up before text messages are
 * turned on, and those of external modules that are on for the site. Built from the site's container the first time
 * they are needed.
 */
final class SmsProviders
{
    /** Core's text message module, whose providers are offered before it is turned on. */
    public const string CORE = 'sms';

    /** @var array<non-empty-string, SmsProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ModuleRegistry $tenant,
        private readonly ModuleRegistry $site,
    ) {
    }

    /**
     * @return array<non-empty-string, SmsProvider> by id, in registration order
     *
     * @throws LogicException when the container builds something else, or two providers have the same id
     */
    public function all(): array
    {
        if ($this->providers === null) {
            $providers = [];
            foreach ($this->tenant->smsProviders() as $registered) {
                if ($registered->module !== self::CORE && !$this->site->has($registered->module)) {
                    continue;
                }
                $provider = $this->container->get($registered->item);
                if (!$provider instanceof SmsProvider) {
                    throw new LogicException(sprintf('Text message provider %s of module "%s" is not a %s.', $registered->item, $registered->module, SmsProvider::class));
                }
                $id = $provider->id();
                if ($id === '' || isset($providers[$id])) {
                    throw new LogicException(sprintf('Text message provider %s of module "%s" uses the id "%s", which is empty or already used.', $registered->item, $registered->module, $id));
                }
                $providers[$id] = $provider;
            }
            $this->providers = $providers;
        }

        return $this->providers;
    }

    public function get(string $id): ?SmsProvider
    {
        return $this->all()[$id] ?? null;
    }
}
