<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Theme\ColorMode;
use SightPress\Core\Theme\Contract\ThemeColoursChanged;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeColours;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeValidator;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * `/admin/appearance/colours`: the main colours of the site's theme in light and dark mode, each a text field with
 * its hex value. Saving checks the theme with the new colours for contrast first ({@see ThemeValidator}); a pair that
 * fails is refused with both colours and the ratio in words, on the field that changed, and the form keeps what was
 * typed. "Use the theme's own colours" removes them. Needs `theme.apply`.
 */
final readonly class ThemeColoursHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/appearance/colours';

    private const string HEX = '/^#[0-9a-fA-F]{6}$/';

    private const string HEX_MESSAGE = 'Write the colour as # and six digits or letters from a to f, for example #0a57c2';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private SiteTheme $site,
        private ThemeColours $colours,
        private ThemeLoader $loader,
        private ThemeValidator $themes,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * The form field of a colour: the mode and the colour name, `-` as `_` (`dark__on_primary`).
     */
    public static function field(string $mode, string $colour): string
    {
        return $mode . '__' . str_replace('-', '_', $colour);
    }

    public static function form(): Form
    {
        $hex = static fn (): array => [new Regex(self::HEX, message: self::HEX_MESSAGE)];

        return Form::create(
            'theme-colours',
            new Field('light__background', 'Background', hint: 'Behind the text of every page.', constraints: $hex()),
            new Field('light__text', 'Text', constraints: $hex()),
            new Field('light__link', 'Links', constraints: $hex()),
            new Field('light__primary', 'Buttons and highlights', constraints: $hex()),
            new Field('light__on_primary', 'Text on buttons', constraints: $hex()),
            new Field('light__border', 'Borders of fields and boxes', constraints: $hex()),
            new Field('light__focus', 'Focus outline', hint: 'Shows where the keyboard is.', constraints: $hex()),
            new Field('dark__background', 'Background', hint: 'Behind the text of every page.', constraints: $hex()),
            new Field('dark__text', 'Text', constraints: $hex()),
            new Field('dark__link', 'Links', constraints: $hex()),
            new Field('dark__primary', 'Buttons and highlights', constraints: $hex()),
            new Field('dark__on_primary', 'Text on buttons', constraints: $hex()),
            new Field('dark__border', 'Borders of fields and boxes', constraints: $hex()),
            new Field('dark__focus', 'Focus outline', hint: 'Shows where the keyboard is.', constraints: $hex()),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AppearanceHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $manifest = $this->site->theme()->manifest();
        $theme = $this->loader->load($this->site->theme()->paths()->root);
        $overrides = $this->colours->overrides($manifest->id);
        $form = self::form()->withValues($this->values(ThemeColours::apply($theme, $overrides)));

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            if (($body['reset'] ?? null) !== null) {
                $this->colours->save($manifest->id, ['light' => [], 'dark' => []]);
                $this->events->dispatch(new ThemeColoursChanged($manifest->id, $manifest->name, [], $actor->userId));

                return $this->screens->redirect($request, self::PATH, new Message('The site uses the colours of {name} again.', ['name' => $manifest->name]));
            }
            $form = $this->validator->submit(self::form(), $body);
            if ($form->isValid()) {
                $chosen = $this->chosen($form, $theme);
                $issues = $this->themes->validate(ThemeColours::apply($theme, $chosen))->issues;
                if ($issues === []) {
                    $this->colours->save($manifest->id, $chosen);
                    $changed = [];
                    foreach ($chosen as $mode => $colours) {
                        foreach (array_keys($colours) as $name) {
                            $changed[] = $mode . '.' . $name;
                        }
                    }
                    $this->events->dispatch(new ThemeColoursChanged($manifest->id, $manifest->name, $changed, $actor->userId));

                    return $this->screens->redirect($request, self::PATH, new Message('The colours are saved.'));
                }
                $form = $form->withErrors($this->errors($issues, $chosen));
            }
        }

        return $this->screens->render($request, '@theme/colours.html.twig', [
            'form' => $form,
            'theme' => $manifest,
            'action' => self::PATH,
            'appearance' => AppearanceHandler::PATH,
            'customised' => $overrides !== ['light' => [], 'dark' => []],
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * @return array<string, string>
     */
    private function values(\SightPress\Core\Theme\Theme $theme): array
    {
        $values = [];
        foreach (ThemeColours::MODES as $mode) {
            foreach (ThemeColours::EDITABLE as $name) {
                $values[self::field($mode, $name)] = (string) $theme->tokens->color($name, $mode === 'light' ? ColorMode::Light : ColorMode::Dark)?->hex;
            }
        }

        return $values;
    }

    /**
     * The colours that differ from the theme's own.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    private function chosen(Form $form, \SightPress\Core\Theme\Theme $theme): array
    {
        $chosen = ['light' => [], 'dark' => []];
        foreach (ThemeColours::MODES as $mode) {
            foreach (ThemeColours::EDITABLE as $name) {
                $hex = strtolower($form->text(self::field($mode, $name)));
                if ($hex !== $theme->tokens->color($name, $mode === 'light' ? ColorMode::Light : ColorMode::Dark)?->hex) {
                    $chosen[$mode][$name] = $hex;
                }
            }
        }

        return $chosen;
    }

    /**
     * One message per field: on the changed colour of each failing pair (its foreground when both changed or
     * neither did), in light or dark mode ("more contrast" counts as its mode).
     *
     * @param list<\SightPress\Core\Theme\ThemeIssue>                          $issues
     * @param array{light: array<string, string>, dark: array<string, string>} $chosen
     *
     * @return array<string, Message>
     */
    private function errors(array $issues, array $chosen): array
    {
        $errors = [];
        foreach ($issues as $issue) {
            $mode = in_array($issue->mode, [ColorMode::Dark, ColorMode::DarkMore], true) ? 'dark' : 'light';
            $foreground = $issue->pair->foreground ?? 'background';
            $background = $issue->pair->background ?? 'background';
            $name = !isset($chosen[$mode][$foreground]) && isset($chosen[$mode][$background]) ? $background : $foreground;
            $values = [
                'foreground' => $foreground,
                'background' => $background,
                'ratio' => number_format(floor(($issue->ratio ?? 0.0) * 100) / 100, 2, '.', ''),
                'minimum' => number_format($issue->pair?->use->minimum() ?? 4.5, 1, '.', ''),
            ];
            $errors[self::field($mode, in_array($name, ThemeColours::EDITABLE, true) ? $name : 'background')] ??= $mode === 'light'
                ? new Message('{foreground} on {background} has a contrast of {ratio}:1 in light mode; it needs at least {minimum}:1. Choose a darker or lighter colour.', $values)
                : new Message('{foreground} on {background} has a contrast of {ratio}:1 in dark mode; it needs at least {minimum}:1. Choose a darker or lighter colour.', $values);
        }

        return $errors;
    }
}
