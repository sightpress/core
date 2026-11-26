<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Template\Message;
use SightPress\Core\Block\Contract\BlockIssue;
use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\BlockViolation;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Block\Contract\ValidatedBlocks;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\Schema\SchemaWalker;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaLibrary;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Checks blocks in this order, per block: shape and known type; upgrade to the current version; the type's schema
 * (with the Symfony validator); rich text sanitised; blocks inside (columns) the same way; its looks (`styles`) against
 * what the type offers on this site, keeping only the choices that aren't the default; then every file used must
 * be in the media library and of the kind the field asks for. All problems are collected, so the editor sees them at once.
 */
final readonly class DefaultBlockValidator implements BlockValidator
{
    /** Media formats that need one kind of file (and maybe one type): kind, MIME types or null, message. */
    private const array MEDIA_FORMATS = [
        SchemaConstraints::FORMAT_IMAGE => [MediaKind::Image, null, 'Choose a picture.'],
        SchemaConstraints::FORMAT_VIDEO => [MediaKind::Video, null, 'Choose a video.'],
        SchemaConstraints::FORMAT_AUDIO => [MediaKind::Audio, null, 'Choose an audio file.'],
        SchemaConstraints::FORMAT_DOCUMENT => [MediaKind::Document, null, 'Choose a document, such as a PDF.'],
        SchemaConstraints::FORMAT_CAPTIONS => [MediaKind::Document, ['text/vtt'], 'Choose a captions file in WebVTT format (.vtt).'],
    ];

    public function __construct(
        private BlockTypeCatalogue $types,
        private ValidatorInterface $validator,
        private RichTextSanitizer $sanitizer,
        private MediaLibrary $media,
    ) {
    }

    public function validate(array $blocks): ValidatedBlocks
    {
        $violations = [];
        $issues = [];
        $media = [];
        $clean = $this->list($blocks, '', true, $violations, $issues, $media);

        $ids = array_values(array_unique(array_column($media, 'id')));
        $found = $ids === [] ? [] : $this->media->files($ids);
        foreach ($media as $reference) {
            $file = $found[$reference['id']] ?? null;
            if ($file === null) {
                $violations[] = new BlockViolation($reference['pointer'], 'This file isn\'t in the media library any more. Choose another one.');
            } elseif (isset(self::MEDIA_FORMATS[$reference['format']])) {
                [$kind, $mimeTypes, $message] = self::MEDIA_FORMATS[$reference['format']];
                if ($file->kind() !== $kind || ($mimeTypes !== null && !in_array($file->mimeType(), $mimeTypes, true))) {
                    $violations[] = new BlockViolation($reference['pointer'], $message);
                }
            }
        }
        if ($violations !== []) {
            throw new InvalidBlocks($violations);
        }

        return new ValidatedBlocks(
            $clean,
            $issues,
            $ids,
            array_map(static fn (array $reference): array => ['id' => $reference['id'], 'pointer' => $reference['pointer']], $media),
        );
    }

    /**
     * @param list<mixed>                              $blocks
     * @param list<BlockViolation>                     $violations
     * @param list<BlockIssue>                         $issues
     * @param list<array{id: int, pointer: string, format: string}>    $media
     *
     * @return list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}>
     */
    private function list(array $blocks, string $at, bool $topLevel, array &$violations, array &$issues, array &$media): array
    {
        $clean = [];
        foreach (array_values($blocks) as $index => $block) {
            $pointer = $at . '/' . $index;
            $result = $this->block($block, $pointer, $topLevel, $violations, $issues, $media);
            if ($result !== null) {
                $clean[] = $result;
            }
        }

        return $clean;
    }

    /**
     * @param list<BlockViolation>                  $violations
     * @param list<BlockIssue>                      $issues
     * @param list<array{id: int, pointer: string, format: string}> $media
     *
     * @return array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}|null
     */
    private function block(mixed $block, string $pointer, bool $topLevel, array &$violations, array &$issues, array &$media): ?array
    {
        if (
            !is_array($block) || !is_string($block['type'] ?? null) || !is_int($block['version'] ?? null)
            || !is_array($block['data'] ?? null) || ($block['data'] !== [] && array_is_list($block['data']))
        ) {
            $violations[] = new BlockViolation($pointer, 'This is not a block: it needs a type, a version and its data.');

            return null;
        }
        $type = $this->types->get($block['type']);
        if ($type === null) {
            $violations[] = new BlockViolation($pointer . '/type', new Message('There is no block type "{type}" on this site. Remove the block or turn on the module that adds it.', ['type' => $block['type']]));

            return null;
        }
        if (!$topLevel && $type instanceof ParentBlockType) {
            $violations[] = new BlockViolation($pointer . '/type', new Message('A "{label}" block can\'t sit inside another block.', ['label' => Message::of($type->label())]));

            return null;
        }
        /** @var array<string, mixed> $stored */
        $stored = $block['data'];
        $data = BlockUpgrader::upgrade($type, $block['version'], $stored);
        if ($data === null) {
            $violations[] = new BlockViolation($pointer . '/version', new Message('This block was saved by a newer version of the "{label}" block. Update the module that adds it.', ['label' => Message::of($type->label())]));

            return null;
        }

        $schema = $type->schema();
        $found = $this->validator->validate($data, SchemaConstraints::for($schema, $type->name()));
        if (count($found) > 0) {
            foreach ($found as $violation) {
                $violations[] = new BlockViolation($pointer . '/data' . self::pointer($violation->getPropertyPath()), (string) $violation->getMessage());
            }

            return null;
        }

        /** @var array<string, mixed> $data */
        $data = SchemaWalker::walk($schema, $data, fn (array $schema, mixed $value): mixed => ($schema['contentMediaType'] ?? null) === SchemaConstraints::HTML && is_string($value)
            ? $this->sanitizer->sanitize($value)
            : $value);
        foreach (SchemaWalker::mediaReferences($schema, $data) as $reference) {
            $media[] = ['id' => $reference['id'], 'pointer' => $pointer . '/data' . $reference['path'], 'format' => $reference['format']];
        }

        if ($type instanceof ParentBlockType) {
            $lists = [];
            foreach ($type->childLists($data) as $listPointer => $children) {
                $lists[$listPointer] = $this->list($children, $pointer . '/data' . $listPointer, false, $violations, $issues, $media);
            }
            $data = $type->withChildLists($data, $lists);
        }

        foreach ($type->a11yRules() as $rule) {
            foreach ($rule->check($data) as $issue) {
                $issues[] = new BlockIssue($pointer, $type->name(), $issue);
            }
        }

        $stylesOffered = $this->types->stylesOf($type->name());
        $problems = BlockStyleChoices::problems($stylesOffered, $block['styles'] ?? null);
        foreach ($problems as $key => $problem) {
            $violations[] = new BlockViolation($pointer . '/styles' . ($key === '' ? '' : '/' . str_replace(['~', '/'], ['~0', '~1'], $key)), $problem);
        }
        $styles = $problems === [] ? BlockStyleChoices::clean($stylesOffered, $block['styles'] ?? null) : [];

        return ['type' => $type->name(), 'version' => $type->version(), 'data' => $data] + ($styles === [] ? [] : ['styles' => $styles]);
    }

    /**
     * `[columns][0][blocks]` → `/columns/0/blocks`.
     */
    private static function pointer(string $propertyPath): string
    {
        preg_match_all('/\[([^\]]*)\]/', $propertyPath, $matches);

        return implode('', array_map(
            static fn (string $part): string => '/' . str_replace(['~', '/'], ['~0', '~1'], $part),
            $matches[1],
        ));
    }
}
