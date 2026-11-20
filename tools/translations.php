<?php

/**
 * Interface text catalogues (I-3): finds every text people see, checks the XLIFF catalogues and keeps them up to date.
 *
 * Usage: php tools/translations.php check|update [--src=<dir>] [--views=<dir>,…] [--catalogues=<dir>]
 *
 * Texts are found
 *  - in templates: every t('…') with a literal text;
 *  - in PHP: every `new Message('…')` / `Message::of('…')`, and the literal texts given to the places that show text
 *    to people: form fields (label, hint, choices, requiredMessage), settings (label, help, choices), permissions,
 *    admin menu items, accessibility issues, publish issues, validation messages (`…Message:` arguments,
 *    `buildViolation()`), flash messages, redirects with a message, the exceptions whose message people read, and
 *    block types (`label()` and the `title` / `description` of their `schema()`).
 *
 * `check` fails (exit code 1) when
 *  - a text is used but not in the en-GB catalogue (missing key), or the en-GB catalogue has a text nothing uses
 *    (unused key);
 *  - a shipped catalogue (every other `messages.<locale>.xlf`) lacks a translation or has one for a text that isn't in
 *    en-GB, or a translation doesn't have the same {placeholders} as its text;
 *  - one of the places above gets text built at run time (`sprintf()`, concatenation …): it can't be translated, so
 *    it must be a `Message` with values.
 * `update` writes the en-GB catalogue from what is used and adds or removes the same units in the other catalogues
 * (new ones without a translation, so `check` lists them), then reports what is left to translate.
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

const SOURCE = 'en-GB';

/** Classes whose constructor takes text people read: class => [argument position or name, …]. */
const TEXT_ARGUMENTS = [
    'Field' => [1, 'label', 'hint', 'requiredMessage', 'choices'],
    'Setting' => [1, 'label', 'help', 'choices'],
    'Permission' => [1, 2, 'label', 'description'],
    'AdminMenuItem' => [1, 'label'],
    'A11yIssue' => [1, 'message'],
    'PublishIssue' => [4, 'message'],
    'Message' => [0, 'text'],
    'PageException' => [0], 'MediaException' => [0], 'MenuException' => [0], 'UserException' => [0],
    'BrandingException' => [0], 'WorkflowException' => [0], 'SiteLanguageException' => [0], 'BlockException' => [0],
    'AccountException' => [0], 'AvatarException' => [0], 'SiteModuleException' => [0],
    'ErrorPage' => [1, 2, 'title', 'paragraphs', 'links'],
    'RoleChoice' => [2, 'description'],
    'OperationResult' => [1, 'message'],
    'BlockViolation' => [1, 'message'],
];

/** Methods whose argument is text people read: method => [position, …]. */
const TEXT_CALLS = [
    'buildViolation' => [0],
    'redirect' => [2],
    'add' => [1], // Flash::add($session, …)
    'of' => [0], // Message::of(…)
];

/**
 * @return array{array<string, list<string>>, list<string>} the texts with where they are used, and the problems
 */
function extractPhp(string $src): array
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $found = [];
    $problems = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // Console commands talk to operators in en-GB (ADR-0007): their text isn't interface text.
        if (str_contains($path, '/Command/') || str_contains($path, '/Cli/')) {
            continue;
        }
        $code = (string) file_get_contents($path);
        $ast = $parser->parse($code) ?? [];
        $visitor = new class ($path) extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $found = [];

            /** @var list<string> */
            public array $problems = [];

            private bool $inSchema = false;

            public function __construct(private readonly string $path)
            {
            }

            public function enterNode(Node $node): null
            {
                // Labels of enums and block types (`label()`, `stateLabel()`) are shown translated.
                if ($node instanceof Node\Stmt\ClassMethod && in_array($node->name->toString(), ['label', 'stateLabel'], true)) {
                    foreach ((new PhpParser\NodeFinder())->findInstanceOf($node, Node\Stmt\Return_::class) as $return) {
                        $this->text($return->expr, $node->name->toString() . '()');
                    }
                }
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'schema' && $this->isBlockType()) {
                    $this->inSchema = true;
                }
                if ($this->inSchema && $node instanceof Node\ArrayItem && $node->key instanceof Node\Scalar\String_ && in_array($node->key->value, ['title', 'description'], true)) {
                    $this->text($node->value, 'schema ' . $node->key->value);
                }
                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                    $class = $node->class->getLast();
                    if (isset(TEXT_ARGUMENTS[$class])) {
                        $this->arguments($node->args, TEXT_ARGUMENTS[$class], $class);
                    }
                    foreach ($node->args as $argument) {
                        if ($argument instanceof Node\Arg && $argument->name !== null && str_ends_with($argument->name->toString(), 'essage') && !isset(TEXT_ARGUMENTS[$class])) {
                            $this->text($argument->value, $class . ' ' . $argument->name->toString());
                        }
                    }
                }
                if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier) {
                    $method = $node->name->toString();
                    $class = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name ? $node->class->getLast() : null;
                    $wanted = match (true) {
                        $method === 'add' => $class === 'Flash',
                        $method === 'of' => $class === 'Message',
                        $method === 'redirect' => $node instanceof Node\Expr\MethodCall,
                        default => isset(TEXT_CALLS[$method]),
                    };
                    if ($wanted) {
                        $this->arguments($node->args, TEXT_CALLS[$method], $method . '()');
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'schema') {
                    $this->inSchema = false;
                }

                return null;
            }

            private function isBlockType(): bool
            {
                return str_contains($this->path, '/Block/Type/') || str_contains($this->path, 'Block.php');
            }

            /**
             * @param array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $arguments
             * @param list<int|string>                        $wanted
             */
            private function arguments(array $arguments, array $wanted, string $where): void
            {
                foreach ($arguments as $index => $argument) {
                    if (!$argument instanceof Node\Arg) {
                        continue;
                    }
                    $key = $argument->name?->toString() ?? $index;
                    if (in_array($key, $wanted, true)) {
                        if ($key === 'choices' || ($argument->value instanceof Node\Expr\Array_ && $key !== 0)) {
                            $this->choices($argument->value, $where);
                        } else {
                            $this->text($argument->value, $where);
                        }
                    }
                }
            }

            private function choices(Node\Expr $value, string $where): void
            {
                if (!$value instanceof Node\Expr\Array_) {
                    return;
                }
                foreach ($value->items as $item) {
                    if ($item !== null && $item->value instanceof Node\Expr\Array_) {
                        $this->choices($item->value, $where);
                    } elseif ($item !== null) {
                        $this->text($item->value, $where . ' choice');
                    }
                }
            }

            private function text(?Node\Expr $value, string $where): void
            {
                if ($value === null) {
                    return;
                }
                $line = $value->getStartLine();
                if ($value instanceof Node\Scalar\String_) {
                    if ($value->value !== '') {
                        $this->found[$value->value][] = $this->path . ':' . $line;
                    }
                } elseif ($value instanceof Node\Expr\Ternary) {
                    $this->text($value->if, $where);
                    $this->text($value->else, $where);
                } elseif ($value instanceof Node\Expr\BinaryOp\Coalesce) {
                    $this->text($value->left, $where);
                    $this->text($value->right, $where);
                } elseif ($value instanceof Node\Expr\Match_) {
                    foreach ($value->arms as $arm) {
                        $this->text($arm->body, $where);
                    }
                } elseif (
                    ($value instanceof Node\Expr\FuncCall && $value->name instanceof Node\Name && in_array($value->name->toString(), ['sprintf', 'vsprintf', 'implode', 'ucfirst', 'str_replace'], true))
                    || $value instanceof Node\Expr\BinaryOp\Concat
                    || $value instanceof Node\Scalar\InterpolatedString
                ) {
                    $this->problems[] = sprintf('%s:%d: %s gets text built at run time; use a Message with values.', $this->path, $line, $where);
                }
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        foreach ($visitor->found as $text => $where) {
            foreach ($where as $place) {
                $found[$text][] = $place;
            }
        }
        $problems = [...$problems, ...$visitor->problems];
    }

    return [$found, $problems];
}

/**
 * @param list<string> $directories
 *
 * @return array<string, list<string>>
 */
function extractTwig(array $directories): array
{
    $found = [];
    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $template = (string) file_get_contents($file->getPathname());
            preg_match_all("/(?<![\\w.])t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $template, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$text, $offset]) {
                $text = stripcslashes($text);
                $found[$text][] = $file->getPathname() . ':' . (substr_count($template, "\n", 0, $offset) + 1);
            }
        }
    }

    return $found;
}

/**
 * @return array<string, string> source => target
 */
function readCatalogue(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        throw new RuntimeException($file . ' is not valid XML.');
    }
    $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');
    $units = [];
    $found = $xml->xpath('//x:unit');
    foreach (is_array($found) ? $found : [] as $unit) {
        $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');
        $source = (string) ($unit->xpath('x:segment/x:source')[0] ?? '');
        $units[$source] = (string) ($unit->xpath('x:segment/x:target')[0] ?? '');
    }

    return $units;
}

/**
 * @param array<string, string> $units source => target ('' for none yet)
 */
function writeCatalogue(string $file, string $locale, array $units): void
{
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->setIndentString('  ');
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('xliff');
    $xml->writeAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:2.0');
    $xml->writeAttribute('version', '2.0');
    $xml->writeAttribute('srcLang', SOURCE);
    $xml->writeAttribute('trgLang', $locale);
    $xml->startElement('file');
    $xml->writeAttribute('id', 'messages');
    foreach ($units as $source => $target) {
        $xml->startElement('unit');
        $xml->writeAttribute('id', substr(hash('sha256', $source), 0, 16));
        $xml->startElement('segment');
        if ($target === '') {
            $xml->writeAttribute('state', 'initial');
        }
        $xml->writeElement('source', $source);
        $xml->writeElement('target', $target);
        $xml->endElement();
        $xml->endElement();
    }
    $xml->endElement();
    $xml->endElement();
    $xml->endDocument();
    file_put_contents($file, $xml->outputMemory());
}

/**
 * @return list<string> the {placeholders} of an ICU message (top level only), sorted
 */
function placeholders(string $text): array
{
    preg_match_all('/\{\{ ?(\w+) ?\}\}|\{(\w+)[,}]/', $text, $matches);
    $names = array_values(array_unique(array_filter([...$matches[1], ...$matches[2]], static fn (string $name): bool => $name !== '')));
    sort($names);

    return $names;
}

$options = getopt('', ['src::', 'views::', 'catalogues::']);
$root = dirname(__DIR__);
$src = is_string($options['src'] ?? null) ? $options['src'] : $root . '/src';
$views = is_string($options['views'] ?? null) ? explode(',', $options['views']) : [$root . '/resources/views', $root . '/vendor/sightpress/theme-default/templates'];
$catalogues = is_string($options['catalogues'] ?? null) ? $options['catalogues'] : $root . '/resources/translations';
$command = $argv[count($argv) - 1] ?? 'check';

[$php, $problems] = extractPhp($src);
$used = extractTwig($views);
foreach ($php as $text => $where) {
    $used[$text] = [...($used[$text] ?? []), ...$where];
}
ksort($used);
$sourceFile = $catalogues . '/messages.' . SOURCE . '.xlf';
$others = [];
$files = glob($catalogues . '/messages.*.xlf');
foreach (is_array($files) ? $files : [] as $file) {
    $locale = substr(basename($file, '.xlf'), strlen('messages.'));
    if ($locale !== SOURCE) {
        $others[$locale] = $file;
    }
}

if ($command === 'update') {
    $units = [];
    foreach (array_keys($used) as $text) {
        $units[(string) $text] = (string) $text;
    }
    writeCatalogue($sourceFile, SOURCE, $units);
    foreach ($others as $locale => $file) {
        $existing = readCatalogue($file);
        $merged = [];
        foreach (array_keys($units) as $text) {
            $merged[$text] = $existing[$text] ?? '';
        }
        writeCatalogue($file, $locale, $merged);
        printf("%s: %d texts, %d to translate.\n", $locale, count($merged), count(array_filter($merged, static fn (string $target): bool => $target === '')));
    }
    printf("%s: %d texts.\n", SOURCE, count($units));
    foreach ($problems as $problem) {
        echo $problem, "\n";
    }
    exit($problems === [] ? 0 : 1);
}

$errors = $problems;
$source = readCatalogue($sourceFile);
foreach ($used as $text => $where) {
    if (!array_key_exists((string) $text, $source)) {
        $errors[] = sprintf('Missing key "%s" (used in %s): run `php tools/translations.php update`.', $text, $where[0]);
    }
}
foreach (array_keys($source) as $text) {
    if (!isset($used[$text])) {
        $errors[] = sprintf('Unused key "%s" in %s: nothing shows it any more.', $text, basename($sourceFile));
    }
}
foreach ($others as $locale => $file) {
    $units = readCatalogue($file);
    foreach (array_keys($source) as $text) {
        $target = $units[$text] ?? '';
        if ($target === '') {
            $errors[] = sprintf('%s: no translation of "%s".', $locale, $text);
        } elseif (placeholders($target) !== placeholders((string) $text)) {
            $errors[] = sprintf('%s: the translation of "%s" has other placeholders: "%s".', $locale, $text, $target);
        }
    }
    foreach (array_diff_key($units, $source) as $text => $target) {
        $errors[] = sprintf('%s: translates "%s", which isn\'t in %s.', $locale, $text, basename($sourceFile));
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
printf("Translations: %d texts, %d catalogues besides %s, %d problems.\n", count($used), count($others), SOURCE, count($errors));
exit($errors === [] ? 0 : 1);
