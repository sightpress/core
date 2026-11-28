<?php

declare(strict_types=1);

namespace SightPress\Core\User\Privacy;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\AvatarKind;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Profile pictures (ADR-0075): the picture someone uploaded, or the one made from their initials, in the site's
 * storage under `avatars/`, and the `avatar_*` columns of their account. Erasing deletes the files.
 */
final readonly class AvatarData implements PersonalDataProvider
{
    public const string KEY = 'user.avatar';

    public function __construct(
        private Connection $connection,
        private FilesystemOperator $storage,
    ) {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Your profile picture'),
            new Message('To show who did what in the admin.'),
            [PersonalDataCategory::Identity],
            LawfulBasis::Contract,
            Retention::whileAccountExists(),
            notes: new Message('The picture you uploaded, or one made from your initials and a colour if you didn’t.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $row = $this->row($user);
        if ($row === null) {
            return [];
        }

        return [[
            'kind' => $row['kind']?->value,
            'colour' => $row['color'],
            'files' => AvatarService::filesOf($row['path'], $row['kind']),
        ]];
    }

    public function erase(UserRef $user): ErasureResult
    {
        $row = $this->row($user);
        if ($row === null) {
            return ErasureResult::nothing();
        }
        $files = 0;
        foreach (AvatarService::filesOf($row['path'], $row['kind']) as $path) {
            try {
                if ($this->storage->fileExists($path)) {
                    $this->storage->delete($path);
                    ++$files;
                }
            } catch (FilesystemException) {
                // Left for the storage owner: the account no longer points at it, and erasure can be re-applied.
            }
        }
        $this->connection->createQueryBuilder()
            ->update('users')
            ->set('avatar_path', 'NULL')
            ->set('avatar_kind', 'NULL')
            ->set('avatar_color', 'NULL')
            ->where('id = :id')
            ->setParameter('id', $user->userId)
            ->executeStatement();

        return new ErasureResult(deleted: 1, files: $files);
    }

    /**
     * @return array{path: string|null, kind: AvatarKind|null, color: string|null}|null
     */
    private function row(UserRef $user): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('avatar_path', 'avatar_kind', 'avatar_color')
            ->from('users')
            ->where('id = :id')
            ->andWhere('avatar_path IS NOT NULL OR avatar_kind IS NOT NULL OR avatar_color IS NOT NULL')
            ->setParameter('id', $user->userId)
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }

        return [
            'path' => is_string($row['avatar_path']) ? $row['avatar_path'] : null,
            'kind' => is_string($row['avatar_kind']) ? AvatarKind::tryFrom($row['avatar_kind']) : null,
            'color' => is_string($row['avatar_color']) ? $row['avatar_color'] : null,
        ];
    }
}
