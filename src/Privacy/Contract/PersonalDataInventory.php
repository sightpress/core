<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Contract;

use SightPress\Core\Infrastructure\Privacy\PrivacyExtensions;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Text\Message;

/**
 * Everything the site keeps about people, from the modules that are on (GD-1, ADR-0075, ADR-0077): data sets,
 * cookies and third parties, plus what core keeps no personal data in and why. *Your data*, *Download your data*,
 * *Delete your account*, the Privacy Policy and the record of processing are built from it.
 */
final readonly class PersonalDataInventory
{
    public function __construct(private PrivacyExtensions $extensions)
    {
    }

    /**
     * @return array<non-empty-string, PersonalDataSet> by key, in registration order
     */
    public function dataSets(): array
    {
        return array_map(static fn (PersonalDataProvider $provider): PersonalDataSet => $provider->describe(), $this->extensions->providers());
    }

    /**
     * @return array<non-empty-string, PersonalDataProvider> by data set key
     */
    public function providers(): array
    {
        return $this->extensions->providers();
    }

    public function provider(string $key): ?PersonalDataProvider
    {
        return $this->extensions->provider($key);
    }

    public function moduleOf(string $key): ?string
    {
        return $this->extensions->moduleOf($key);
    }

    /**
     * @return list<CookieDeclaration>
     */
    public function cookies(): array
    {
        return $this->extensions->cookies();
    }

    /**
     * @return array<non-empty-string, ProcessorDeclaration> by id
     */
    public function processors(): array
    {
        return $this->extensions->processors();
    }

    /**
     * What core keeps no personal data in, and why, for *Your data* and the Privacy Policy.
     *
     * @return array<string, Message> by feature
     */
    public static function notStored(): array
    {
        return [
            'analytics' => new Message('Visitor statistics keep no internet address, browser details or anything else that identifies a visitor: only a code made with a key that is thrown away every day. People who are signed in, and browsers that ask not to be tracked, are never counted.'),
            'search' => new Message('Site search keeps only the text of published pages, never accounts. What people search for is counted like visits, without anything that identifies them.'),
            'contact' => new Message('Messages sent with a contact form are sent by email and never stored on the site.'),
        ];
    }
}
