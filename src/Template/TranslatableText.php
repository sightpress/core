<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use Throwable;

/**
 * For exceptions whose message people read: built with a {@see Message} (or a plain en-GB text), they keep it for
 * {@see text()}, so a screen can show it in the viewer's language; `getMessage()` is the en-GB sentence, for logs.
 */
trait TranslatableText
{
    private Message $text;

    public function __construct(string|Message $message = '', int $code = 0, ?Throwable $previous = null)
    {
        $this->text = Message::of($message);
        parent::__construct((string) $this->text, $code, $previous);
    }

    /**
     * The message, to be shown translated.
     */
    public function text(): Message
    {
        return $this->text;
    }
}
