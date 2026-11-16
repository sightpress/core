<?php

declare(strict_types=1);

namespace SightPress\Core\Contact;

use SightPress\Core\Block\Contract\HttpBlockContext;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A form visitors use to send a message (name, email address, message) to an address the editor chooses. The
 * address is never shown on the page. Spam is kept out with a hidden field people leave empty and a limit of
 * messages per visitor ({@see Http\ContactFormHandler}).
 *
 * The form needs the visitor's request (CSRF token, what the last submission left): without one (Markdown, feeds)
 * the block shows nothing, as it holds no content of its own.
 */
final readonly class ContactFormBlock implements BlockType
{
    public function __construct(private TemplateRenderer $templates)
    {
    }

    public function name(): string
    {
        return 'contact_form';
    }

    public function label(): string
    {
        return 'Contact form';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['recipient'],
            'properties' => [
                'recipient' => ['type' => 'string', 'maxLength' => 254, 'format' => 'email'],
                'subject' => ['type' => 'string', 'maxLength' => 100],
                'submit_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                'success_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $request = $context instanceof HttpBlockContext ? $context->request() : null;
        if ($request === null) {
            return '';
        }
        $id = ContactForm::id($data);
        $state = ContactForm::recall(Session::fromRequest($request), $id);

        return $this->templates->render($request, '@contact/form.html.twig', [
            'form' => $state['form'],
            'sent' => $state['sent'],
            'action' => ContactForm::PATH,
            'return' => $request->getUri()->getPath(),
            'honeypot' => ContactForm::HONEYPOT,
            'submit_text' => self::text($data, 'submit_text'),
            'success_text' => self::text($data, 'success_text'),
        ]);
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return '';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }

    /**
     * The editor's text, or null for core's default (translated in the template).
     *
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
