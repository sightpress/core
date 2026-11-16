<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaAccess;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Rbac\Contract\Actor;

#[CoversClass(MediaAccess::class)]
#[UsesClass(Media::class)]
#[UsesClass(MediaFormat::class)]
#[UsesClass(Actor::class)]
final class MediaAccessTest extends TestCase
{
    public function testPublishedFilesAreForEveryoneAndTheOthersForPeopleWhoManageMedia(): void
    {
        $access = new MediaAccess();
        $file = new Media(str_repeat('a', 32), str_repeat('b', 64), new MediaFormat('text/plain', MediaKind::Document, 'txt', 'Text'), 'notes.txt', 'notes.txt', 1, null, null, [], null, new DateTimeImmutable());
        $visitor = Actor::anonymous();
        $member = new Actor(7, ['page.view']);
        $contributor = new Actor(8, ['media.upload']);

        self::assertSame([false, false, true], [$access->canView($visitor, $file), $access->canView($member, $file), $access->canView($contributor, $file)]);

        $file->publish(new DateTimeImmutable());

        self::assertSame([true, true, true], [$access->canView($visitor, $file), $access->canView($member, $file), $access->canView($contributor, $file)]);
    }
}
