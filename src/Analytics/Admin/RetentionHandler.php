<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;

/**
 * `POST /admin/analytics/retention`: how many months of analytics the site keeps (1 to 25), from the Analytics
 * screen; the change is in the audit log. Older figures go at the next hourly roll-up. Needs `analytics.manage`.
 */
final readonly class RetentionHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/analytics/retention';

    public function __construct(
        private AdminScreens $screens,
        private AnalyticsRetention $retention,
    ) {
    }

    public static function form(int $months): Form
    {
        return Form::create(
            'analytics-retention',
            new Field('months', new Message('Months to keep analytics for'), FieldType::Number, new Message('From 1 to 25. Figures older than this are deleted within the hour. 25 months keeps two full years to compare.'), inputmode: 'numeric'),
        )->withValues(['months' => (string) $months]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AnalyticsModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        $body = $request->getParsedBody();
        $months = is_array($body) && is_string($body['months'] ?? null) && preg_match('/^\d{1,2}$/', $body['months']) === 1 ? (int) $body['months'] : 0;
        if ($months < AnalyticsRetention::MIN || $months > AnalyticsRetention::MAX) {
            return $this->screens->redirect($request, AnalyticsHandler::PATH . '#analytics-manage', new Message('Choose how many months to keep analytics for, from 1 to 25.'));
        }
        $this->retention->change($months, $actor->userId);

        return $this->screens->redirect($request, AnalyticsHandler::PATH . '#analytics-manage', new Message('Analytics are now kept for {count, plural, one {# month} other {# months}}.', ['count' => $months]));
    }
}
