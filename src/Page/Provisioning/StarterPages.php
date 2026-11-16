<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Provisioning;

use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\OwnerType;
use Twig\Environment;

/**
 * New site, step {@see SiteProvisioning::CONTENT}: writes the starter pages in the site's main language — Home
 * (the home page, in the `home` layout), About, Contact (with a contact form that writes to the first admin),
 * Privacy, Terms and an Accessibility statement — from the templates for a person or an organization
 * (`@page/starter/<owner type>/`), and publishes them. The first admin is their author.
 */
final readonly class StarterPages
{
    public const string TEMPLATES = '@page/starter/';

    public function __invoke(SiteProvisioning $event): void
    {
        $services = $event->services;
        $editor = Services::get($services, PageEditor::class);
        $twig = Services::get($services, Environment::class);
        $now = Services::get($services, ClockInterface::class)->now();
        $site = $event->site;
        $person = $site->ownerType === OwnerType::Person;
        $owner = trim($event->admin->firstName . ' ' . $event->admin->lastName);
        $name = mb_substr($site->name, 0, 150);
        $variables = [
            'site_name' => $site->name,
            'owner_name' => $owner,
            'site_url' => rtrim($event->baseUrl, '/'),
            'date' => $now->format('j F Y'),
        ];

        $pages = [];
        $created = [];
        foreach ([
            'home' => [sprintf('Welcome to %s', $name), sprintf('The website of %s.', $person ? $owner : $name)],
            'about' => [$person ? 'About me' : 'About us', $person ? sprintf('Who %s is and what they do.', $owner) : sprintf('Who %s is and what we do.', $name)],
            'contact' => [$person ? 'Contact me' : 'Contact us', sprintf('Send %s a message.', $person ? $owner : $name)],
            'privacy' => ['Privacy notice', 'What personal information this website collects and what is done with it.'],
            'terms' => ['Terms of use', 'The terms for using this website.'],
            'accessibility' => ['Accessibility statement', 'How accessible this website is, and how to report a problem.'],
        ] as $slug => [$title, $description]) {
            $blocks = [['type' => 'rich_text', 'version' => 1, 'data' => [
                'html' => $twig->render(self::TEMPLATES . ($person ? 'person' : 'organization') . '/' . $slug . '.html.twig', $variables),
            ]]];
            if ($slug === 'contact') {
                $blocks[] = ['type' => 'contact_form', 'version' => 1, 'data' => [
                    'recipient' => $event->admin->email,
                    'subject' => mb_substr(sprintf('Message from the website of %s', $name), 0, 100),
                ]];
            }
            $page = $editor->create($site->defaultLocale, new PageContent($title, $slug, null, mb_substr($description, 0, PageContent::DESCRIPTION_MAX_LENGTH), $blocks), null, $event->adminId());
            if ($slug === 'home') {
                $page->setLayout('home', $now);
            }
            $page->changeState(PageState::Published, $now);
            $pages[] = $title;
            $created[] = $page;
        }
        Services::get($services, PageRepository::class)->flush();
        Services::get($services, PageAddresses::class)->setHome($created[0]);

        $event->report(sprintf('Published the starter pages: %s.', implode(', ', $pages)));
    }
}
