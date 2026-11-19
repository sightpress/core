<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

/**
 * The figures kept per day in `analytics_daily` (metric, dimension, item, value). A dimension of `''` is the
 * day's total.
 */
final class Metric
{
    /** People, counted once per day each (D20). */
    public const string VISITORS = 'visitors';

    /** Pages read by people. */
    public const string VIEWS = 'views';

    /** Addresses asked for that don't exist (dimension {@see PATH}). */
    public const string NOT_FOUND = 'not_found';

    /** Pages and files read by bots (dimension {@see BOT}: `<name>|<kind>|<resource>`). */
    public const string BOT_READS = 'bot_reads';

    /** Site searches (dimension {@see QUERY} for the queries kept). */
    public const string SEARCHES = 'searches';

    /** Site searches that found nothing (dimension {@see QUERY}). */
    public const string EMPTY_SEARCHES = 'empty_searches';

    public const string TOTAL = '';

    /** `<page id>:<locale>` */
    public const string PAGE = 'page';

    /** see {@see Classification\SourceClassifier} */
    public const string SOURCE = 'source';

    public const string LOCALE = 'locale';

    public const string DEVICE = 'device';

    public const string PATH = 'path';

    public const string BOT = 'bot';

    public const string QUERY = 'query';
}
