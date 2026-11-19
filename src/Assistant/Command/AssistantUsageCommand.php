<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Assistant\AssistantUse;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:assistant:usage <slug> [--month=YYYY-MM]`: the tokens a site's assistant used in a month (UTC), per task,
 * and this month's budget.
 */
final readonly class AssistantUsageCommand extends SiteAssistantCommand
{
    public function name(): string
    {
        return 'site:assistant:usage';
    }

    public function description(): string
    {
        return 'Shows the tokens a site\'s AI assistant used in a month, per task, and the monthly budget.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('month', 'Month as YYYY-MM, in UTC (default: this month)', CommandParameterType::Option),
        ];
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        $thisMonth = AssistantUse::monthOf(Services::get($services, ClockInterface::class)->now());
        $month = trim((string) $input->option('month'));
        $month = $month === '' ? $thisMonth : $month;
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $output->error(sprintf('"%s" isn\'t a month written as YYYY-MM.', $month));

            return self::INVALID;
        }
        $tasks = Services::get($services, AssistantUsageStore::class)->byTask($month);
        $output->line(sprintf('AI assistant use of "%s" in %s (UTC):', $site->slug, $month));
        if ($tasks === []) {
            $output->line('  Not used.');
        }
        foreach ($tasks as $task) {
            $output->line(sprintf(
                '  %s: %d %s%s, %d tokens in, %d out',
                $task['task'],
                $task['uses'],
                $task['uses'] === 1 ? 'use' : 'uses',
                $task['failed'] > 0 ? sprintf(' (%d failed)', $task['failed']) : '',
                $task['input'],
                $task['output'],
            ));
        }
        if ($month === $thisMonth) {
            $budget = Services::get($services, Assistant::class)->budget();
            $output->line($budget->isLimited()
                ? sprintf('Budget: %d of %d tokens used this month (%d%%).%s', $budget->used, $budget->limit, $budget->percent(), $budget->isUsedUp() ? ' The assistant refuses until next month.' : ($budget->isNearlyUsedUp() ? ' Nearly used up.' : ''))
                : sprintf('Budget: no limit (%d tokens used this month).', $budget->used));
        }

        return self::SUCCESS;
    }
}
