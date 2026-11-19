<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Assistant\Task\AskTask;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `assistant:ask <slug> <question>`: asks a site's AI assistant a question from the console and prints the answer,
 * in the site's main language. Counted against the site's budget like any other use.
 */
final readonly class AskAssistantCommand extends SiteAssistantCommand
{
    public function name(): string
    {
        return 'assistant:ask';
    }

    public function description(): string
    {
        return 'Asks a site\'s AI assistant a question and prints the answer.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('question', 'Your question:', required: true),
        ];
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        try {
            $result = Services::get($services, Assistant::class)->run(
                AskTask::ID,
                ['question' => trim((string) $input->argument('question'))],
                new SiteAssistantContext($site->defaultLocale),
            );
        } catch (AssistantError $error) {
            $output->error($error->getMessage());

            return self::FAILURE;
        }
        $output->line($result->suggestion->text);
        if ($result->truncated) {
            $output->line('(The answer was cut short.)');
        }
        if ($result->budget->isNearlyUsedUp()) {
            $output->line(sprintf('This site has used %d%% of its AI assistant budget for this month.', $result->budget->percent()));
        }

        return self::SUCCESS;
    }
}
