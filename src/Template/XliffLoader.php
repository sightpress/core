<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Reads an XLIFF 2.0 catalogue (as `tools/translations.php` writes them): every unit's source is the key, its target
 * the translation. Units without a translation are left out, so the en-GB text shows instead.
 */
final class XliffLoader implements LoaderInterface
{
    public const string NAMESPACE = 'urn:oasis:names:tc:xliff:document:2.0';

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_string($resource) || !is_file($resource)) {
            throw new NotFoundResourceException(sprintf('There is no catalogue at "%s".', is_string($resource) ? $resource : get_debug_type($resource)));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($resource, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new InvalidResourceException(sprintf('The catalogue "%s" is not valid XML.', $resource));
        }
        $catalogue = new MessageCatalogue($locale);
        $xml->registerXPathNamespace('x', self::NAMESPACE);
        $segments = $xml->xpath('//x:unit/x:segment');
        foreach (is_array($segments) ? $segments : [] as $segment) {
            $segment->registerXPathNamespace('x', self::NAMESPACE);
            $source = (string) ($segment->xpath('x:source')[0] ?? '');
            $target = (string) ($segment->xpath('x:target')[0] ?? '');
            if ($source !== '' && $target !== '') {
                $catalogue->set($source, $target, $domain);
            }
        }

        return $catalogue;
    }
}
