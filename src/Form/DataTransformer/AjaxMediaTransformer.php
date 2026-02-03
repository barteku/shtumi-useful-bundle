<?php

namespace Shtumi\UsefulBundle\Form\DataTransformer;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;

class AjaxMediaTransformer implements DataTransformerInterface
{
    private EntityManagerInterface $em;
    private string $mediaClass;

    public function __construct(EntityManagerInterface $em, string $mediaClass)
    {
        $this->em = $em;
        $this->mediaClass = $mediaClass;
    }

    /**
     * Transforms a media entity to a string (ID)
     */
    public function transform($value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_object($value)) {
            throw new UnexpectedTypeException($value, 'object');
        }

        if (!method_exists($value, 'getId')) {
            throw new UnexpectedTypeException($value, 'object with getId method');
        }

        return (string) $value->getId();
    }

    /**
     * Transforms a string (ID) back to a media entity
     */
    public function reverseTransform($value)
    {
        if ('' === $value || null === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new UnexpectedTypeException($value, 'numeric');
        }

        $media = $this->em->getRepository($this->mediaClass)->find((int) $value);

        if (null === $media) {
            throw new TransformationFailedException(sprintf('Media with ID "%s" could not be found', $value));
        }

        return $media;
    }
}
