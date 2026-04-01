<?php

namespace Shtumi\UsefulBundle\Form\DataTransformer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\DataTransformerInterface;

class MultipleAjaxMediaTransformer implements DataTransformerInterface
{
    private EntityManagerInterface $em;
    private string $mediaClass;

    public function __construct(EntityManagerInterface $em, string $mediaClass)
    {
        $this->em = $em;
        $this->mediaClass = $mediaClass;
    }

    /**
     * Collection/array of media entities -> comma-separated string of IDs
     */
    public function transform($value): string
    {
        if (null === $value || (is_countable($value) && count($value) === 0)) {
            return '';
        }

        $ids = [];
        foreach ($value as $media) {
            if (is_object($media) && method_exists($media, 'getId') && $media->getId() !== null) {
                $ids[] = (string) $media->getId();
            }
        }

        return implode(',', $ids);
    }

    /**
     * Comma-separated string of IDs -> ArrayCollection of media entities
     */
    public function reverseTransform($value): ArrayCollection
    {
        if (null === $value || '' === $value) {
            return new ArrayCollection();
        }

        if (is_array($value)) {
            $ids = $value;
        } else {
            $ids = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        $collection = new ArrayCollection();
        $repo = $this->em->getRepository($this->mediaClass);

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $media = $repo->find((int) $id);
            if ($media !== null) {
                $collection->add($media);
            }
        }

        return $collection;
    }
}
