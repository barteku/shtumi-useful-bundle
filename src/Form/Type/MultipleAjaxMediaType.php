<?php

namespace Shtumi\UsefulBundle\Form\Type;

use Doctrine\ORM\EntityManagerInterface;
use Shtumi\UsefulBundle\Form\DataTransformer\MultipleAjaxMediaTransformer;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MultipleAjaxMediaType extends AbstractType
{
    private MediaManagerInterface $mediaManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        MediaManagerInterface $mediaManager,
        EntityManagerInterface $entityManager
    ) {
        $this->mediaManager = $mediaManager;
        $this->entityManager = $entityManager;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'multiple' => true,
            'context' => 'default',
            'provider' => 'sonata.media.provider.image',
            'max_files' => 20,
            'accept' => 'image/*',
        ]);

        $resolver->setAllowedTypes('context', 'string');
        $resolver->setAllowedTypes('provider', 'string');
        $resolver->setAllowedTypes('accept', 'string');
        $resolver->setAllowedTypes('max_files', 'int');
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'multiple_ajax_media';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $media = $this->mediaManager->create();
        $mediaClass = get_class($media);

        $transformer = new MultipleAjaxMediaTransformer(
            $this->entityManager,
            $mediaClass
        );
        $builder->addViewTransformer($transformer);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['context'] = $options['context'];
        $view->vars['provider'] = $options['provider'];
        $view->vars['max_files'] = $options['max_files'];
        $view->vars['accept'] = $options['accept'];

        $existingMedia = [];
        $data = $form->getData();
        if ($data !== null && is_iterable($data)) {
            foreach ($data as $media) {
                if (!is_object($media) || !method_exists($media, 'getId') || $media->getId() === null) {
                    continue;
                }
                $existingMedia[] = $media;
            }
        }

        $view->vars['existing_media'] = $existingMedia;
    }
}
