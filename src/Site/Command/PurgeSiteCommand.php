<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:purge <slug>`: removes a deleted site from the registry for good. Its database is kept unless
 * `--drop-database` is given. Only a site that was deleted with `site:delete` can be purged.
 */
final readonly class PurgeSiteCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
        private DatabaseProvisioner $databases,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:purge';
    }

    public function description(): string
    {
        return 'Removes a deleted site for good. Its database is kept unless you add --drop-database.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the deleted site:', required: true),
            new CommandParameter('drop-database', 'Also delete the site\'s database. This can\'t be undone.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = $input->argument('slug') ?? '';
        $site = $this->sites->find($slug);
        if ($site === null) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        if ($site->status !== SiteStatus::Deleted) {
            $output->error(sprintf(
                'The site "%s" is %s. Only deleted sites can be purged: run site:delete %s first.',
                $slug,
                $site->status->value,
                $slug,
            ));

            return self::FAILURE;
        }

        $drop = $input->flag('drop-database');
        $database = $this->database($site->databaseDsn);
        if ($drop) {
            try {
                $this->databases->drop($database ?? throw new DatabaseDsnException(
                    'The database address can\'t be read with this APP_SECRET, so the database can\'t be deleted.',
                ));
            } catch (DatabaseDsnException $exception) {
                $output->error($exception->getMessage() . ' The site was not purged.');

                return self::FAILURE;
            }
        }

        $this->sites->remove($slug);
        $this->containers->evict($slug);
        $this->loggers->create('sites')->notice(sprintf('Site "%s" purged.', $slug), [
            'site' => $slug,
            'database_dropped' => $drop,
        ]);
        $output->line(sprintf('The site "%s" was removed from the registry.', $slug));
        $described = $database?->describe() ?? 'database';
        $output->line($drop
            ? sprintf('Deleted its %s.', $described)
            : sprintf('Its %s was kept: delete it yourself when you no longer need it.', $described));

        return self::SUCCESS;
    }

    private function database(string $encrypted): ?DatabaseDsn
    {
        try {
            return DatabaseDsn::parse($this->secretBox->decrypt($encrypted));
        } catch (SecretBoxException | DatabaseDsnException) {
            return null;
        }
    }
}
