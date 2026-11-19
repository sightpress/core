<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Assistant\AssistantBudget;
use SightPress\Core\Assistant\AssistantConfiguration;
use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Provider\ProviderModels;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantConfig;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/assistant` (A-3): the AI assistant's settings, for people with `assistant.manage`. Whether it is ready
 * (or what is missing), then the service: each service with what it is in plain words (radios), its model (a select
 * grouped by service, or a typed name for a service without a list), its address when it needs one and a write-only
 * key ("••••1234"; typing a new one replaces it, "Remove key" removes it); "Test connection"; who may use it and the
 * monthly budget, with what is used this month; and what is sent and kept. Sites of an organisation that chooses the
 * service for them (`assistant.site_keys` false) only see its choice.
 *
 * POST `op=service|remove_key|limits` saves one part (audited as a settings change, keys only) and comes back.
 */
final readonly class AssistantSettingsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant';

    /** Separates the service from the model in the model select's values (`anthropic::claude-haiku-5-5`). */
    public const string MODEL_SEPARATOR = '::';

    public function __construct(
        private AdminScreens $screens,
        private AssistantSetup $setup,
        private AssistantSettings $settings,
        private AssistantExtensions $extensions,
        private ProviderModels $models,
        private EndpointGuard $guard,
        private AssistantBudget $budget,
        private RoleGrants $roles,
        private TenantConfig $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AssistantModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        $service = $this->serviceForm()->withValues($this->savedService());
        $limits = $this->limitsForm($actor)->withValues($this->savedLimits());
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $op = $body['op'] ?? null;
            if ($op === 'service' && $this->setup->sitesMayChoose()) {
                $service = $this->saveService($service, $body, $actor);
                if ($service === null) {
                    return $this->screens->redirect($request, self::PATH, new Message('The AI service is saved.'));
                }
            } elseif ($op === 'remove_key' && $this->setup->sitesMayChoose()) {
                return $this->screens->redirect($request, self::PATH, $this->settings->removeKey($actor->userId)
                    ? new Message('The API key is removed.')
                    : new Message('There was no key to remove.'));
            } elseif ($op === 'limits') {
                $limits = $this->saveLimits($limits, $body, $actor);
                if ($limits === null) {
                    return $this->screens->redirect($request, self::PATH . '#assistant-limits-title', new Message('Who may use the assistant and its budget are saved.'));
                }
            } else {
                return $this->screens->redirect($request, self::PATH);
            }
        }

        return $this->render($request, $service, $limits);
    }

    private function render(ServerRequestInterface $request, Form $service, Form $limits): ResponseInterface
    {
        $configuration = $this->setup->configuration();
        $tenant = $this->config->assistant;
        $tenantProvider = $tenant->provider === null ? null : $this->extensions->provider($tenant->provider);
        $budget = $this->budget->status($configuration->budget);
        $providers = [];
        foreach ($this->extensions->providers() as $id => $provider) {
            $providers[] = ['id' => $id, 'label' => $provider->label(), 'description' => $provider->description(), 'listed' => $provider->models() !== [], 'needs_address' => $provider->needsBaseUrl()];
        }

        return $this->screens->render($request, '@assistant/settings.html.twig', [
            'action' => self::PATH,
            'test_action' => AssistantTestHandler::PATH,
            'may_choose' => $this->setup->sitesMayChoose(),
            'ready' => $configuration->isReady(),
            'problem' => $configuration->problem,
            'current' => $configuration->provider === null ? null : [
                'provider' => $configuration->provider->label(),
                'model' => $configuration->model === null ? null : $this->models->label($configuration->provider->id(), $configuration->model),
                'from_organisation' => $configuration->source === AssistantConfiguration::TENANT,
            ],
            'organisation' => $tenantProvider === null ? null : ['provider' => $tenantProvider->label(), 'model' => $tenant->model, 'has_key' => $tenant->apiKey !== null],
            'providers' => $providers,
            'service' => $service,
            'masked_key' => $this->settings->maskedKey(),
            'limits' => $limits,
            'budget' => $budget,
            'organisation_budget' => $tenant->monthlyBudget,
            'error_count' => count($service->errors()) + count($limits->errors()),
        ], $service->isValid() && $limits->isValid() ? 200 : 422);
    }

    private function serviceForm(): Form
    {
        $providers = [];
        $models = [];
        $custom = [];
        foreach ($this->extensions->providers() as $id => $provider) {
            $providers[$id] = $provider->label();
            if ($provider->models() === []) {
                $custom[] = $provider->label();
                continue;
            }
            $group = [];
            foreach ($provider->models() as $model) {
                $group[$id . self::MODEL_SEPARATOR . $model] = $this->models->label($id, $model);
            }
            $models[$provider->label()] = $group;
        }

        return Form::create(
            'assistant-service',
            new Field('provider', 'AI service', FieldType::Radios, choices: $providers, requiredMessage: 'Choose an AI service.'),
            new Field('model', 'Model', FieldType::Select, hint: 'Models of the chosen service. Bigger models write better and cost more.', choices: $models),
            new Field('custom_model', 'Model name', hint: new Message('Only for {services}: the name of the model, as the service writes it.', ['services' => implode(', ', $custom)]), required: false),
            new Field('base_url', 'Address of the service', FieldType::Url, hint: 'Only for services that need it: the address of the service’s API, starting with https://, for example https://ai.example.org/v1.', required: false),
            new Field('key', 'New API key', FieldType::Password, hint: 'Leave it empty to keep the saved key. The key is stored encrypted and never shown again.', required: false, autocomplete: 'off'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function savedService(): array
    {
        $provider = $this->settings->provider() ?? '';
        $model = $this->settings->model() ?? '';
        $listed = $provider !== '' && ($this->extensions->provider($provider)?->models() ?? []) !== [];

        return [
            'provider' => $provider,
            'model' => $listed && $model !== '' ? $provider . self::MODEL_SEPARATOR . $model : '',
            'custom_model' => $listed ? '' : $model,
            'base_url' => $this->settings->baseUrl() ?? '',
        ];
    }

    /**
     * Saves the service, model, address and key; null when saved, or the form with what is wrong.
     *
     * @param array<array-key, mixed> $body
     */
    private function saveService(Form $form, array $body, Actor $actor): ?Form
    {
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? trim($body[$name]) : '';
        $values = ['provider' => $text('provider'), 'model' => $text('model'), 'custom_model' => $text('custom_model'), 'base_url' => $text('base_url')];
        $key = $text('key');
        $errors = [];
        $provider = $this->extensions->provider($values['provider']);
        $model = '';
        if ($provider === null) {
            $errors['provider'] = new Message('Choose an AI service.');
        } elseif ($provider->models() !== []) {
            [$of, $model] = array_pad(explode(self::MODEL_SEPARATOR, $values['model'], 2), 2, '');
            if ($of !== $provider->id() || !in_array($model, $provider->models(), true)) {
                $errors['model'] = new Message('Choose a model of {service}.', ['service' => $provider->label()]);
            }
        } else {
            $model = $values['custom_model'];
            if ($model === '' || mb_strlen($model) > 200) {
                $errors['custom_model'] = new Message('Enter the name of the model to use with {service}.', ['service' => $provider->label()]);
            }
        }
        $baseUrl = $values['base_url'];
        if ($provider !== null && $provider->needsBaseUrl()) {
            $problem = $baseUrl === '' ? new Message('Enter the address of the service, starting with https://.') : $this->guard->reason($baseUrl);
            if ($problem !== null) {
                $errors['base_url'] = $problem;
            }
        } else {
            $baseUrl = '';
        }
        if ($errors !== [] || $provider === null) {
            return $form->submitted($values, $errors);
        }
        $this->settings->connect($provider->id(), $model, $baseUrl === '' ? null : $baseUrl, $key === '' ? null : $key, $actor->userId);

        return null;
    }

    private function limitsForm(Actor $actor): Form
    {
        $roles = [];
        foreach ($this->roles->grantableBy($actor) as $role) {
            if (!in_array($role->name, ['admin', 'member'], true)) {
                $roles[$role->name] = $role->label;
            }
        }

        return Form::create(
            'assistant-limits',
            new Field('roles', 'Who may use the assistant', FieldType::Checkboxes, hint: 'Admins always may.', required: false, choices: $roles),
            new Field('budget', 'Monthly budget in tokens', FieldType::Number, hint: new Message('How many tokens (pieces of words, sent and received) the site may use each month. Leave it empty to use your organisation’s budget ({tokens, plural, =0 {no limit} other {# tokens}}); 0 means no limit.', ['tokens' => $this->config->assistant->monthlyBudget]), required: false, inputmode: 'numeric'),
        );
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function savedLimits(): array
    {
        $budget = $this->settings->budget();

        return ['roles' => array_values(array_diff($this->settings->roles(), ['admin'])), 'budget' => $budget === null ? '' : (string) $budget];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function saveLimits(Form $form, array $body, Actor $actor): ?Form
    {
        $choices = array_map(strval(...), array_keys($form->field('roles')->choices));
        $chosen = is_array($body['roles'] ?? null) ? array_values(array_filter($body['roles'], is_string(...))) : [];
        $roles = array_values(array_intersect($choices, $chosen));
        $budget = is_string($body['budget'] ?? null) ? trim($body['budget']) : '';
        if ($budget !== '' && preg_match('/^\d{1,12}$/', $budget) !== 1) {
            return $form->submitted(['roles' => $roles, 'budget' => $budget], ['budget' => new Message('Enter the budget as a whole number of tokens, such as 200000, or leave it empty.')]);
        }
        $this->settings->limit($budget === '' ? null : max(0, (int) $budget), ['admin', ...$roles], $actor->userId);

        return null;
    }
}
