<?php

namespace Shtumi\UsefulBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Shtumi\UsefulBundle\Form\DataTransformer\AjaxMediaTransformer;
use Sonata\MediaBundle\Model\MediaManagerInterface;
use Doctrine\ORM\EntityManagerInterface;

class AjaxMediaType extends AbstractType
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
            'multiple' => false,
            'context' => 'default',
            'provider' => 'sonata.media.provider.file',
        ]);

        $resolver->setAllowedTypes('context', 'string');
        $resolver->setAllowedTypes('provider', 'string');
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getName(): ?string
    {
        return 'shtumi_ajax_media';
    }

    public function getBlockPrefix(): string
    {
        return 'shtumi_ajax_media';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Get media class from MediaManager by creating a temporary instance
        $media = $this->mediaManager->create();
        $mediaClass = get_class($media);
        
        $transformer = new AjaxMediaTransformer(
            $this->entityManager,
            $mediaClass
        );
        $builder->addViewTransformer($transformer);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['context'] = $options['context'];
        $view->vars['provider'] = $options['provider'];
        $view->vars['multiple'] = $options['multiple'];
    }
}
