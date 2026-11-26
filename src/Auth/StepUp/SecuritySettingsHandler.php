<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TwoStepPolicy;
use SightPress\Core\User\Contract\AccountPhones;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * *Site settings → Security* (`/admin/settings/security`, N-6, D33), for people with `security.manage`: two-step
 * confirmation on or off, with what it does in plain words. Only while the tenant leaves it to the site
 * (`two_step: optional`); with `required` the screen says so and has no switch, with `off` it doesn't exist. It needs
 * text messages. Turning it on needs your own confirmed number and a code, so you can't lock yourself out; turning it
 * off is a critical action like the others.
 */
final readonly class SecuritySettingsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/security';

    public const string PERMISSION = 'security.manage';

    public function __construct(
        private AdminScreens $screens,
        private TwoStep $twoStep,
        private StepUpGate $gate,
        private AccountPhones $phones,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        if ($this->twoStep->policy() === TwoStepPolicy::Off) {
            throw new HttpNotFoundException($request);
        }
        $form = self::form()->withValues(['two_step' => $this->twoStep->siteChoice() ? 'on' : 'off']);
        $mayChoose = $this->twoStep->policy() === TwoStepPolicy::Optional && $this->twoStep->textsOn();
        if ($request->getMethod() === 'POST' && $mayChoose) {
            $body = $request->getParsedBody();
            $on = is_array($body) && ($body['two_step'] ?? null) === 'on';
            $hasNumber = $actor->userId !== null && ($this->phones->phoneOf($actor->userId)?->hasNumber() ?? false);
            if ($on && !$hasNumber) {
                $form = $form->submitted(['two_step' => 'on'], ['two_step' => new Message('Add your own mobile number first, on My account: you need it to confirm changes once this is on.')]);
            } elseif ($on && !$this->twoStep->isOn() && !StepUpSession::isConfirmed(Session::fromRequest($request), $this->clock->now()->getTimestamp())) {
                // Turning it on is confirmed with a code too, so the number surely works.
                return $this->gate->hold($request);
            } else {
                $changed = $this->twoStep->choose($on, $actor->userId);

                return $this->screens->redirect($request, self::PATH, match (true) {
                    !$changed => new Message('Nothing changed.'),
                    $on => new Message('Two-step confirmation is on. Important changes now need a code sent to your mobile number.'),
                    default => new Message('Two-step confirmation is off.'),
                });
            }
        }

        return $this->screens->render($request, '@auth/security-settings.html.twig', [
            'action' => self::PATH,
            'form' => $form,
            'on' => $this->twoStep->isOn(),
            'required' => $this->twoStep->policy() === TwoStepPolicy::Required,
            'texts_on' => $this->twoStep->textsOn(),
            'may_choose' => $mayChoose,
        ], $form->errors() === [] ? 200 : 422);
    }

    public static function form(): Form
    {
        return Form::create('security', new Field('two_step', 'Two-step confirmation', FieldType::Radios, choices: [
            'on' => new Message('On: important changes need a code sent by text message'),
            'off' => new Message('Off: a password is enough'),
        ]));
    }
}
