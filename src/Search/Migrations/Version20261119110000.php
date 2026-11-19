<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The site search index (SR-1, ADR-0011), built with the Schema API only:
 *
 * - `search_document`: one row per document and locale, with its text (for results and extracts), who may find it
 *   and the number of terms in each part (for scoring);
 * - `search_posting`: which terms each part of a document has (title, summary, body) and how often;
 * - `search_word`: the words of each locale and their terms, so the last word of a query also finds longer words
 *   ("accessib" finds "accessibility").
 */
final class Version20261119110000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the search_document, search_posting and search_word tables.';
    }

    public function up(Schema $schema): void
    {
        $documents = $schema->createTable('search_document');
        $documents->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $documents->addColumn('source', Types::STRING, ['length' => 64]);
        $documents->addColumn('ref', Types::STRING, ['length' => 191]);
        $documents->addColumn('locale', Types::STRING, ['length' => 35]);
        $documents->addColumn('url_path', Types::STRING, ['length' => 2048]);
        $documents->addColumn('title', Types::STRING, ['length' => 500]);
        $documents->addColumn('summary', Types::TEXT, ['length' => 65535]);
        $documents->addColumn('body', Types::TEXT, ['length' => 16777215]);
        $documents->addColumn('breadcrumb', Types::TEXT, ['length' => 65535]);
        $documents->addColumn('visibility', Types::STRING, ['length' => 16]);
        $documents->addColumn('title_length', Types::INTEGER);
        $documents->addColumn('summary_length', Types::INTEGER);
        $documents->addColumn('body_length', Types::INTEGER);
        $documents->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $documents->addColumn('indexed_at', Types::DATETIME_IMMUTABLE);
        $documents->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $documents->addUniqueIndex(['source', 'ref', 'locale'], 'search_document_key_unique');
        $documents->addIndex(['locale', 'visibility'], 'search_document_locale_idx');

        // Surrogate keys and plain indexes: on MySQL's default collation two different terms can compare equal.
        $postings = $schema->createTable('search_posting');
        $postings->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $postings->addColumn('term', Types::STRING, ['length' => 64]);
        $postings->addColumn('document_id', Types::INTEGER);
        $postings->addColumn('part', Types::SMALLINT);
        $postings->addColumn('frequency', Types::INTEGER);
        $postings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $postings->addIndex(['term', 'document_id'], 'search_posting_term_idx');
        $postings->addIndex(['document_id'], 'search_posting_document_idx');
        $postings->addForeignKeyConstraint('search_document', ['document_id'], ['id'], ['onDelete' => 'CASCADE'], 'search_posting_document_fk');

        $words = $schema->createTable('search_word');
        $words->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $words->addColumn('locale', Types::STRING, ['length' => 35]);
        $words->addColumn('word', Types::STRING, ['length' => 64]);
        $words->addColumn('term', Types::STRING, ['length' => 64]);
        $words->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $words->addIndex(['locale', 'word'], 'search_word_locale_word_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('search_posting');
        $schema->dropTable('search_document');
        $schema->dropTable('search_word');
    }
}
