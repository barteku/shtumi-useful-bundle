<?php

namespace Shtumi\UsefulBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
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
            'chunk_size_mb' => 1,
        ]);

        $resolver->setAllowedTypes('context', 'string');
        $resolver->setAllowedTypes('provider', 'string');
        $resolver->setAllowedTypes('chunk_size_mb', ['int', 'string']);
        $resolver->setNormalizer('chunk_size_mb', static function (Options $options, $value): int {
            $normalized = (int) $value;
            return $normalized > 0 ? $normalized : 1;
        });
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

        // When editing: if user submits without changing the file, the hidden field may be empty
        // (e.g. not rendered with value). Preserve the existing media id so we don't lose the file.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $submitted = $event->getData();
            if ($submitted !== null && $submitted !== '') {
                return;
            }
            $form = $event->getForm();
            $parent = $form->getParent();
            if ($parent === null) {
                return;
            }
            $entity = $parent->getData();
            if (!is_object($entity)) {
                return;
            }
            $name = $form->getName();
            $getter = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
            if (!method_exists($entity, $getter)) {
                return;
            }
            $current = $entity->$getter();
            if ($current === null) {
                return;
            }
            if (is_object($current) && method_exists($current, 'getId')) {
                $event->setData((string) $current->getId());
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['context'] = $options['context'];
        $view->vars['provider'] = $options['provider'];
        $view->vars['multiple'] = $options['multiple'];
        $view->vars['chunk_size_mb'] = $options['chunk_size_mb'];
        // Pass the media entity so the template can show existing file (preview, name, etc.)
        // The transformed value is the id string; the template needs the object for display.
        $view->vars['media'] = $form->getData();
    }
}
