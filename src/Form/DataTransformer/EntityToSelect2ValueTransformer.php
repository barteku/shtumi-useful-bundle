<?php

namespace Shtumi\UsefulBundle\Form\DataTransformer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Form\DataTransformerInterface;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Exception\ErrorMappingException;

class EntityToSelect2ValueTransformer implements DataTransformerInterface
{

    protected $em;
    protected $class;
    protected $unitOfWork;
    protected $multiple;


    public function __construct(EntityManager $em, $class, $multiple = false)
    {
        $this->em = $em;
        $this->unitOfWork = $this->em->getUnitOfWork();
        $this->class = $class;
        $this->multiple = $multiple;
    }

    public function transform($entity)
    {

        if($this->multiple){
            if(is_array($entity)){
                $entity = new ArrayCollection($entity);
            }

            if($entity instanceof Collection){
                if ($entity->count() == 0){
                    return json_encode([]);
                }else{
                    $items = [];
                    foreach ($entity as $item) {
                        $items[] = [
                            'id' => $item->getId(),
                            'text' => (string)$item
                        ];
                    }

                    return json_encode($items);
                }
            }else{
                return json_encode([]);
            }
        }else {


            if (null === $entity || '' === $entity || (is_array($entity) && count($entity) == 0)) {
                return json_encode(array(
                    'id' => null,
                    'text' => '== Choose value =='
                ));
            }
            if (!is_object($entity)) {
                throw new UnexpectedTypeException($entity, 'object');
            }
            if (!$this->unitOfWork->isInIdentityMap($entity)) {
                throw new ErrorMappingException('Entities passed to the choice field must be managed');
            }

            return json_encode(array(
                'id' => $entity->getId(),
                'text' => (string)$entity
            ));
        }
    }

    public function reverseTransform($id)
    {
        if ('' === $id || null === $id) {
            if($this->multiple) {
                return [];
            }else{
                return null;
            }
        }

        if($this->multiple){
            $ids = explode(',', $id);

            $entities = $this->em->getRepository($this->class)->findBy(['id' => $ids]);

            if(!count($entities)){
                throw new TransformationFailedException(sprintf('The entity with key "%s" could not be found', $id));
            }

            return $entities;
        }else{

            $entity = $this->em->getRepository($this->class)->findOneById($id);

            if ($entity === null) {
                throw new TransformationFailedException(sprintf('The entity with key "%s" could not be found', $id));
            }

            return $entity;
        }
    }
}
