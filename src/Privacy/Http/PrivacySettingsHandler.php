<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Privacy\PrivacyModule;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantRetention;
use Slim\Exception\HttpForbiddenException;

/**
 * *Settings → Privacy* (`/admin/settings/privacy`, GD-2): how long the site keeps each kind of personal data, each a
 * labelled select within the limits whoever runs the site set, with what happens to older records. Saving is audited.
 * Needs `privacy.manage`.
 */
final readonly class PrivacySettingsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/privacy';

    /** Lengths offered, when the limits allow them; the default and both limits are always offered too. */
    private const array DAYS = [1, 2, 3, 7, 14, 30, 60, 90, 180, 365, 730];

    private const array MONTHS = [2, 3, 6, 12, 13, 18, 24, 36, 48, 60, 72, 84];

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private RetentionPolicy $retention,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(PrivacyModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        $form = $this->form();
        $values = [];
        foreach ($this->retention->keys() as $key) {
            $values[$key] = (string) $this->retention->value($key);
        }
        $form = $form->withValues($values);
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $form = $this->validator->submit($this->form(), is_array($body) ? $body : []);
            if ($form->isValid()) {
                $chosen = [];
                foreach ($this->retention->keys() as $key) {
                    $chosen[$key] = (int) $form->text($key);
                }
                $changed = $this->retention->change($chosen, $actor->userId);

                return $this->screens->redirect($request, self::PATH, $changed === [] ? new Message('Nothing was changed.') : new Message('How long personal data is kept has been saved. Older records are deleted within a day.'));
            }
        }

        return $this->screens->render($request, '@privacy/settings.html.twig', [
            'form' => $form,
            'action' => self::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    public function form(): Form
    {
        $fields = [];
        foreach ($this->retention->keys() as $key) {
            [$label, $hint] = self::texts($key);
            $fields[] = new Field($key, $label, FieldType::Select, $hint, choices: $this->choices($key));
        }

        return Form::create('privacy', ...$fields);
    }

    /**
     * @return array<int, Message> days or months => label
     */
    private function choices(string $key): array
    {
        $limit = $this->retention->limit($key);
        $months = TenantRetention::inMonths($key);
        $values = array_filter([...($months ? self::MONTHS : self::DAYS), $limit['default'], $limit['min'], $limit['max']], static fn (int $value): bool => $value >= $limit['min'] && $value <= $limit['max']);
        $values = array_values(array_unique($values));
        sort($values);
        $choices = [];
        foreach ($values as $value) {
            $choices[$value] = match (true) {
                $months && $value === $limit['default'] => new Message('{count, plural, one {# month} other {# months}} (suggested)', ['count' => $value]),
                $months => new Message('{count, plural, one {# month} other {# months}}', ['count' => $value]),
                $value === $limit['default'] => new Message('{count, plural, one {# day} other {# days}} (suggested)', ['count' => $value]),
                default => new Message('{count, plural, one {# day} other {# days}}', ['count' => $value]),
            };
        }

        return $choices;
    }

    /**
     * @return array{Message, Message} the label and what the choice does
     */
    private static function texts(string $key): array
    {
        return match ($key) {
            TenantRetention::AUDIT => [new Message('Audit log'), new Message('Entries older than this are deleted. Until then they show who changed what, for the site’s security.')],
            TenantRetention::UNVERIFIED => [new Message('Accounts never confirmed'), new Message('Accounts whose email address was never confirmed are deleted this long after they were made.')],
            TenantRetention::INVITATIONS => [new Message('Invitations'), new Message('Invitations are deleted this long after they expired or were accepted, with the name and email address in them.')],
            TenantRetention::CODES => [new Message('6-digit codes'), new Message('Used and expired codes are deleted after this. Only a scrambled form of each is kept anyway.')],
            TenantRetention::SMS => [new Message('Text messages sent'), new Message('The record of each text message, with its text and the masked number, is deleted after this.')],
            TenantRetention::ASSISTANT => [new Message('Use of the AI assistant'), new Message('The record of what the assistant was used for and how much is deleted after this. A shorter time can’t keep a whole month for the budget.')],
            default => [new Message('Other personal data'), new Message('Records older than this are deleted.')],
        };
    }
}
