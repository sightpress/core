<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Workflow;

use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Message;
use Psr\Http\Message\ServerRequestFactoryInterface;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PermissionHolders;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountDirectory;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * When a page is submitted for review, emails everyone who may publish (editors and admins, except whoever submitted
 * it) a link to the page in the admin.
 */
final readonly class ReviewRequestMailer
{
    /** Address of a page in the admin's page editor (C-7.2). */
    public const string EDIT_PATH = '/admin/pages/%d';

    public function __construct(
        private PageRepository $pages,
        private PermissionHolders $holders,
        private AccountDirectory $accounts,
        private MailSender $mail,
        private TemplateRenderer $templates,
        private ServerRequestFactoryInterface $requests,
        private Site $site,
        private AccountPreferences $preferences,
        private Translations $translations,
    ) {
    }

    public function __invoke(PageTransitioned $event): void
    {
        $page = $event->transition === PageTransition::Submit ? $this->pages->find($event->pageId) : null;
        if ($page === null) {
            return;
        }
        $translation = $page->translation($this->site->defaultLocale) ?? (array_values($page->translations())[0] ?? null);
        $title = $translation?->title() ?? 'Untitled page';
        $author = $event->actorId === null ? null : ($this->accounts->findAccounts([$event->actorId])[0] ?? null);
        $base = 'https://' . ($this->site->domains[0] ?? 'localhost');
        $request = $this->requests->createServerRequest('GET', $base . '/');
        $reviewers = array_values(array_diff($this->holders->userIdsWith('page.publish'), [$event->actorId]));

        foreach ($this->accounts->findAccounts($reviewers) as $reviewer) {
            if (!$reviewer->isActive()) {
                continue;
            }
            $context = [
                'site_name' => $this->site->name,
                'name' => $reviewer->effectiveName(),
                'author' => $author?->effectiveName(),
                'title' => $title,
                'link' => $base . sprintf(self::EDIT_PATH, $event->pageId),
            ];
            $viewer = Viewer::recipient($this->preferences->preferencesOf((int) $reviewer->id()), $this->site);
            $own = $request->withAttribute(Viewer::class, $viewer);
            $this->mail->send(new Email()
                ->to(new Address($reviewer->email(), $reviewer->effectiveName()))
                ->subject($this->translations->translate(new Message('Please review "{title}" on {site}', ['title' => $title, 'site' => $this->site->name]), [], $viewer->locale))
                ->text($this->templates->render($own, '@page/email/review.txt.twig', $context))
                ->html($this->templates->render($own, '@page/email/review.html.twig', $context)));
        }
    }
}
