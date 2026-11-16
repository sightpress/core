<?php

declare(strict_types=1);

namespace SightPress\Core\Contact;

use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Session;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The contact form's fields, its id and what one submission leaves in the session for the page to show after the
 * redirect (Post/Redirect/Get): the values and errors, or that the message was sent.
 *
 * A form's id comes from the block's data, so a submission always reaches the form that was on the page; when an
 * editor changes the form in the meantime, the old one is gone.
 */
final class ContactForm
{
    public const string PATH = '/contact-form';

    public const string HONEYPOT = 'website';

    private const string SESSION = 'contact_form';

    /**
     * @param array<string, mixed> $data the block's data
     */
    public static function id(array $data): string
    {
        ksort($data);

        return 'contact-' . substr(hash('sha256', (string) json_encode($data)), 0, 12);
    }

    public static function form(string $id): Form
    {
        return Form::create(
            $id,
            new Field('name', 'Your name', autocomplete: 'name', constraints: [new Length(max: 100, maxMessage: 'Use {{ limit }} characters or fewer.')]),
            new Field('email', 'Your email address', FieldType::Email, hint: 'We\'ll reply to this address.', autocomplete: 'email'),
            new Field('message', 'Your message', FieldType::Textarea, constraints: [new Length(max: 5000, maxMessage: 'Use {{ limit }} characters or fewer.')]),
        );
    }

    /**
     * Keeps a submission's outcome for the next time the page shows the form.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    public static function remember(Session $session, string $id, array $values, array $errors, bool $sent): void
    {
        $bag = &$session->bag(self::SESSION);
        $bag[$id] = ['values' => $sent ? [] : $values, 'errors' => $errors, 'sent' => $sent];
    }

    /**
     * The form as the last submission left it (once; it is forgotten after this), or empty.
     *
     * @return array{form: Form, sent: bool}
     */
    public static function recall(Session $session, string $id): array
    {
        $bag = &$session->bag(self::SESSION);
        $state = $bag[$id] ?? null;
        unset($bag[$id]);
        $form = self::form($id);
        if (!is_array($state)) {
            return ['form' => $form, 'sent' => false];
        }
        $values = self::strings($state['values'] ?? null);
        $errors = self::strings($state['errors'] ?? null);

        return [
            'form' => $errors === [] ? $form->withValues($values) : $form->submitted($values, $errors),
            'sent' => ($state['sent'] ?? false) === true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $strings[$key] = $item;
            }
        }

        return $strings;
    }
}
