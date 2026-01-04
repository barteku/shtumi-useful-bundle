<?php

namespace Shtumi\UsefulBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class DependentFilteredEntityController extends AbstractController
{

    /**
     * @var ManagerRegistry $em
     */
    private $em;

    /**
     * @var TranslatorInterface $translator
     */
    private $translator;


    private $filteredEntities;

    private $filteredValues;


    public function __construct(ManagerRegistry $em, TranslatorInterface $translator, ParameterBagInterface $parameterBag){
        $this->em = $em;
        $this->translator = $translator;

        $this->filteredEntities = $parameterBag->get('shtumi.dependent_filtered_entities');
        $this->filteredValues = $parameterBag->get('shtumi.dependent_filtered_values');
    }


    public function getOptions(Request $request)
    {

        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');
        $empty_value  = $request->get('empty_value');


        $entity_inf = $this->filteredEntities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            $this->denyAccessUnlessGranted($entity_inf['role']);
        }

        $qb = $this->em
            ->getRepository($entity_inf['class'])
            ->createQueryBuilder('e')
            ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('parent_id', $parent_id);


        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            call_user_func(array($repository, $entity_inf['callback']), $qb);
        }

        $results = $qb->getQuery()->getResult();

        if (empty($results)) {
            return new Response('<option value="">' . $this->translator->trans($entity_inf['no_result_msg']) . '</option>');
        }

        $html = '';
        if ($empty_value !== false)
            $html .= '<option value="">' . $this->translator->trans($empty_value) . '</option>';

        $getter =  $this->getGetterName($entity_inf['property']);

        foreach($results as $result)
        {
            if ($entity_inf['property'])
                $res = $result->$getter();
            else $res = (string)$result;

            $html = $html . sprintf("<option value=\"%d\">%s</option>",$result->getId(), $res);
        }

        return new Response($html);

    }


    public function getJson(Request $request)
    {
        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');
        $empty_value  = $request->get('empty_value');

        $entity_inf = $this->filteredEntities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            $this->denyAccessUnlessGranted($entity_inf['role']);
        }

        $term = $request->get('term');
        $maxRows = $request->get('maxRows', 20);

        $qb = $this->em->getRepository($entity_inf['class'])->createQueryBuilder('e')
            ->select('e')
        ;

        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            call_user_func(array($repository, $entity_inf['callback']), $qb, $parent_id, $term);
        }else {
            if(isset($term['term'])) {
                $like = '%' . $term['term'] . '%';
            }else{
                $like = '%';
            }


            $property = $entity_inf['property'];
            if (!$entity_inf['property_complicated']) {
                $property = 'e.' . $property;
            }

            $qb->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
                ->setParameter('parent_id', $parent_id)
                ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
                ->setParameter('like', $like)
            ;

            if ($entity_inf['case_insensitive']) {
                $qb->andWhere('LOWER(' . $property . ') LIKE LOWER(:like)');
            } else {
                $qb->andWhere($property . ' LIKE :like');
            }
        }

        $qb->setMaxResults($maxRows);
        $results = $qb->getQuery()->getResult();

        $res = array();
        foreach ($results AS $r){
            $res[] = array(
                'id' => $r->getId(),
                'text' => (string)$r
            );
        }

        return new JsonResponse($res);
    }

    public function getSingleValue(Request $request)
    {
        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');

        if (!isset($this->filteredValues[$entity_alias])) {
            throw new \InvalidArgumentException(sprintf('Entity alias "%s" is not configured in dependent_filtered_values.', $entity_alias));
        }

        $valueConfig = $this->filteredValues[$entity_alias];

        if ($valueConfig['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            $this->denyAccessUnlessGranted($valueConfig['role']);
        }

        $qb = $this->em
            ->getRepository($valueConfig['class'])
            ->createQueryBuilder('e')
            ->where('e.' . $valueConfig['parent_property'] . ' = :parent_id')
            ->setParameter('parent_id', $parent_id)
            ->setMaxResults(1);

        if (null !== $valueConfig['callback']) {
            $repository = $qb->getEntityManager()->getRepository($valueConfig['class']);

            if (!method_exists($repository, $valueConfig['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $valueConfig['callback'], get_class($repository)));
            }

            call_user_func(array($repository, $valueConfig['callback']), $qb, $parent_id);
        }

        $results = $qb->getQuery()->getResult();

        if (empty($results)) {
            return new JsonResponse(['value' => null]);
        }

        // Get the first result
        $result = $results[0];

        // Extract value using property
        if ($valueConfig['property']) {
            $getter = $this->getGetterName($valueConfig['property']);
            $value = $result->$getter();
        } else {
            // Default: convert entity to string
            $value = (string)$result;
        }

        return new JsonResponse([
            'value' => $value
        ]);
    }

    private function getGetterName($property)
    {
        $name = "get";
        $name .= mb_strtoupper($property[0]) . substr($property, 1);

        while (($pos = strpos($name, '_')) !== false){
            $name = substr($name, 0, $pos) . mb_strtoupper(substr($name, $pos+1, 1)) . substr($name, $pos+2);
        }

        return $name;

    }
}
