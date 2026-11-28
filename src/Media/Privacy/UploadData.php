<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Privacy\ExportedDate;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Who added each file (`media.uploaded_by`) and the person's uploads that are still arriving (`media_upload`, M-8,
 * ADR-0075). Files in the media library belong to the site (D46): they stay, under the *Former user* tombstone.
 * Uploads that never finished are cancelled, with their waiting parts.
 */
final readonly class UploadData implements PersonalDataProvider
{
    public const string KEY = 'media.uploads';

    public function __construct(
        private Connection $connection,
        private UploadSessions $sessions,
        private UploadFolder $folder,
        private BucketUploads $bucket,
    ) {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Files you added'),
            new Message('To show who added a file to the media library, and to finish an upload that arrives in parts.'),
            [PersonalDataCategory::Account],
            LawfulBasis::Contract,
            Retention::whileAccountExists(),
            ErasureRule::Pseudonymise,
            new Message('The files belong to the site, so they stay when an account is deleted and show “Former user” as who added them. Uploads that never finished are removed.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $files = $this->connection->createQueryBuilder()
            ->select('id', 'original_name', 'kind', 'size', 'created_at')
            ->from('media')
            ->where('uploaded_by = :id')
            ->orderBy('created_at')
            ->addOrderBy('id')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();
        $records = array_map(static fn (array $row): array => [
            'file' => $row['original_name'],
            'media_id' => Row::int($row['id']),
            'kind' => $row['kind'],
            'bytes' => Row::int($row['size']),
            'added_at' => Row::date($row['created_at']),
        ], $files);
        foreach ($this->sessions->ofUser($user->userId) as $session) {
            if ($session->completedAt === null) {
                $records[] = [
                    'unfinished_upload' => $session->fileName,
                    'bytes' => $session->totalBytes,
                    'received_bytes' => $session->receivedBytes,
                    'started_at' => ExportedDate::of($session->createdAt),
                ];
            }
        }

        return $records;
    }

    public function erase(UserRef $user): ErasureResult
    {
        $deleted = 0;
        foreach ($this->sessions->ofUser($user->userId) as $session) {
            // As ChunkedUploads::cancel() and DirectUploads::cancel() do, for every state the upload is in.
            if ($session->mode !== UploadSession::DIRECT) {
                $this->folder->delete($session->id);
            } elseif (!$session->isComplete()) {
                try {
                    $this->bucket->abort($session);
                } catch (StorageException) {
                    // The bucket couldn't be reached: the row goes anyway, and the bucket's expiry rule ends the upload.
                }
            }
            $this->sessions->delete($session->id);
            ++$deleted;
        }
        $kept = Row::int($this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('media')
            ->where('uploaded_by = :id')
            ->setParameter('id', $user->userId)
            ->fetchOne());

        return new ErasureResult(deleted: $deleted, pseudonymised: $kept);
    }
}
