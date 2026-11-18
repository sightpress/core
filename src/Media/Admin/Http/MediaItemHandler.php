<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use Locale;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/admin/media/{id}`: one file, with a preview, what it is, its address, and a form for its descriptions: alt text
 * (pictures) and caption in each of the site's languages, a transcript (video and audio), and — for people who may
 * publish — whether it is published. Needs `media.upload`.
 */
final readonly class MediaItemHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private MediaAdmin $admin,
        private MediaEditor $editor,
        private MediaTypes $types,
        private MediaUrlGenerator $urls,
        private Site $site,
    ) {
    }

    /**
     * The details form. Fields per language are named `alt_<n>`, `caption_<n>` and `transcript_<n>`, n being the
     * language's position in the site's list; with one language the labels don't name it. Texts left empty in a
     * language fall back to the main language's where the file is shown.
     */
    public function form(Media $media, bool $canPublish): Form
    {
        $fields = [];
        $values = [];
        $many = count($this->site->locales) > 1;
        foreach (array_values($this->site->locales) as $index => $locale) {
            $language = new LanguageName($locale);
            if ($media->kind() === MediaKind::Image) {
                $fields[] = new Field(
                    'alt_' . $index,
                    $many ? new Message('Alt text in {language}', ['language' => $language]) : 'Alt text',
                    FieldType::Textarea,
                    hint: 'Say what the picture shows and why it is there, as you would to someone on the phone. Pages can still mark it as decorative where it adds nothing.',
                    required: false,
                    constraints: [new Length(max: Media::ALT_MAX_LENGTH, maxMessage: 'Alt text must be {{ limit }} characters or fewer')],
                );
                $values['alt_' . $index] = $media->altText($locale) ?? '';
            }
            $fields[] = new Field(
                'caption_' . $index,
                $many ? new Message('Caption in {language}', ['language' => $language]) : 'Caption',
                FieldType::Textarea,
                hint: 'Shown under the file on pages that show captions.',
                required: false,
                constraints: [new Length(max: Media::CAPTION_MAX_LENGTH, maxMessage: 'The caption must be {{ limit }} characters or fewer')],
            );
            $values['caption_' . $index] = $media->caption($locale) ?? '';
            if ($media->kind()->hasSpeech()) {
                $fields[] = new Field(
                    'transcript_' . $index,
                    $many ? new Message('Transcript in {language}', ['language' => $language]) : 'Transcript',
                    FieldType::Textarea,
                    hint: $locale === $this->site->defaultLocale
                        ? 'Everything that is said, and other sounds that matter, for people who can’t hear it. Pages with this file show a link to it.'
                        : 'Leave it empty to use the transcript in the main language.',
                    required: false,
                    constraints: [new Length(max: Media::TRANSCRIPT_MAX_LENGTH, maxMessage: 'The transcript is too long')],
                );
                // The main language's field also shows a transcript written before files had one per language.
                $values['transcript_' . $index] = ($locale === $this->site->defaultLocale ? $media->transcript($locale) : $media->ownTranscript($locale)) ?? '';
            }
        }
        if ($canPublish) {
            $fields[] = new Field('published', 'Published', FieldType::Checkbox, hint: 'Published files can be seen by everyone who has their address. Unpublished files can only be seen by people who can upload media.', required: false);
            $values['published'] = $media->isPublished() ? '1' : '';
        }

        return Form::create('details', ...$fields)->withValues($values);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $media] = $this->admin->target($request);
        $canPublish = $actor->has(MediaAdmin::PUBLISH);
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $actor, $media, $this->form($media, $canPublish));
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit($this->form($media, $canPublish), is_array($body) ? $body : []);
        if (!$form->isValid()) {
            return $this->show($request, $actor, $media, $form, 422);
        }
        $descriptions = [];
        $transcripts = [];
        foreach (array_values($this->site->locales) as $index => $locale) {
            $descriptions[$locale] = [
                'alt' => $media->kind() === MediaKind::Image ? $form->text('alt_' . $index) : null,
                'caption' => $form->text('caption_' . $index),
            ];
            if ($media->kind()->hasSpeech()) {
                $transcripts[$locale] = $form->text('transcript_' . $index);
            }
        }
        $changed = $this->editor->update(
            $media,
            $descriptions,
            $media->kind()->hasSpeech() ? $transcripts : null,
            $canPublish ? $form->text('published') === '1' : null,
            $actor->userId,
            $this->site->defaultLocale,
        );

        return $this->screens->redirect($request, MediaAdmin::path($media), $changed === []
            ? 'Nothing was changed.'
            : new Message('The details of {original_name} have been saved.', ['original_name' => $media->originalName()]));
    }

    private function show(ServerRequestInterface $request, Actor $actor, Media $media, Form $form, int $status = 200): ResponseInterface
    {
        return $this->screens->render($request, '@media_admin/item.html.twig', [
            'media' => $media,
            'form' => $form,
            'url' => $this->urls->url($media),
            'preview' => $media->kind() === MediaKind::Image
                ? $this->urls->url($media, in_array(640, $media->variantWidths(), true) ? 640 : null)
                : null,
            'type' => $this->types->label($media->mimeType()) ?? $media->mimeType(),
            'size' => MediaLimits::describe($media->size()),
            'alt' => $media->altText($this->site->defaultLocale),
            'can_delete' => MediaAdmin::canDelete($actor, $media),
            'can_publish' => $actor->has(MediaAdmin::PUBLISH),
        ], $status);
    }
}
