<?php

declare(strict_types=1);

namespace SightPress\Core\Contact;

use SightPress\Core\Contact\Http\ContactFormHandler;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The contact form block and the address it is sent to (`POST /contact-form`), which emails the message to the
 * address the editor chose. Always on.
 */
final class ContactModule implements ModuleInterface
{
    public const string ID = 'contact';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('block', '^0.1'), new ModuleRequirement('page', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->blockTypes()->add(ContactFormBlock::class);
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/contact');

        $services = $registrar->services();
        $services->autowire(ContactMailer::class);
        $services->autowire(ContactFormHandler::class);

        $registrar->routes()->add(new Route('contact.form', ContactForm::PATH, ContactFormHandler::class, ['POST']));
    }
}
