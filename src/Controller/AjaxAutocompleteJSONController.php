<?php

namespace Shtumi\UsefulBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class AjaxAutocompleteJSONController extends AbstractController
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


    public function __construct(ManagerRegistry $em, TranslatorInterface $translator, ParameterBagInterface $parameterBag){
        $this->em = $em;
        $this->translator = $translator;

        $this->filteredEntities = $parameterBag->get('shtumi.autocomplete_entities');
    }

    public function getJSONAction(Request $request)
    {

        $entity_alias = $request->get('entity_alias');
        $entity_inf = $this->filteredEntities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            $this->denyAccessUnlessGranted($entity_inf['role']);
        }

        $letters = $request->get('letters');
        $maxRows = $request->get('maxRows');

        switch ($entity_inf['search']){
            case "begins_with":
                $like = $letters . '%';
            break;
            case "ends_with":
                $like = '%' . $letters;
            break;
            case "contains":
                $like = '%' . $letters . '%';
            break;
            default:
                throw new \Exception('Unexpected value of parameter "search"');
        }

	    $property = $entity_inf['property'];

        $qb = $this->em
            ->getRepository($entity_inf['class'])
            ->createQueryBuilder('e')
            ->select('e.' . $property)
        ;


        if ($entity_inf['case_insensitive']) {
            $qb->where('e.' . $property .' LIKE LOWER(:like)');
        } else {
            $qb->where('e.' . $property .' LIKE :like');
        }

        $qb->setParameter('like', $like);


        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            call_user_func(array($repository, $entity_inf['callback']), $qb);
        }

        $results = $qb->getQuery()
            ->setMaxResults($maxRows)
            ->getScalarResult();
        ;

        //var_dump($results);die;

        $res = array();
        foreach ($results AS $r){
            $res[] = $r[$property];
        }

        return new JsonResponse($res);

    }
}
