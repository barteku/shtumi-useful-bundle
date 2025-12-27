<?php

namespace Shtumi\UsefulBundle\Form\Type;

use Shtumi\UsefulBundle\Form\DataTransformer\EntityToIdTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityManagerInterface;



class DependentFilteredEntityType extends AbstractType
{

    private $entityManager;

    private $filteredEntities;

    public function __construct(EntityManagerInterface $entityManager, $filteredEntities)
    {
        $this->entityManager = $entityManager;
        $this->filteredEntities = $filteredEntities;
    }


    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'empty_value'       => '',
            'entity_alias'      => null,
            'parent_field'      => null,
            'compound'          => false
        ));
    }

    public function getBlockPrefix(): ?string
    {
        return 'shtumi_dependent_filtered_entity';
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $entities = $this->filteredEntities;
        $options['class'] = $entities[$options['entity_alias']]['class'];
        $options['property'] = $entities[$options['entity_alias']]['property'];

        $options['no_result_msg'] = $entities[$options['entity_alias']]['no_result_msg'];

        $builder->addViewTransformer(new EntityToIdTransformer(
            $this->entityManager,
            $options['class']
        ), true);

        $builder->setAttribute("parent_field", $options['parent_field']);
        $builder->setAttribute("entity_alias", $options['entity_alias']);
        $builder->setAttribute("no_result_msg", $options['no_result_msg']);
        $builder->setAttribute("empty_value", $options['empty_value']);

    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['parent_field'] = $form->getConfig()->getAttribute('parent_field');
        $view->vars['entity_alias'] = $form->getConfig()->getAttribute('entity_alias');
        $view->vars['no_result_msg'] = $form->getConfig()->getAttribute('no_result_msg');
        $view->vars['empty_value'] = $form->getConfig()->getAttribute('empty_value');
        
        // Find parent field in form hierarchy
        $parentField = $form->getConfig()->getAttribute('parent_field');
        if ($parentField) {
            $parentFormView = $this->findParentFieldInHierarchy($view, $parentField);
            $view->vars['parent_field_view'] = $parentFormView;
        }
    }
    
    /**
     * Traverse up the form hierarchy to find the parent field
     */
    private function findParentFieldInHierarchy(FormView $view, $parentFieldName)
    {
        $current = $view;
        
        // Traverse up the form hierarchy
        while (isset($current->parent) && $current->parent !== null) {
            $current = $current->parent;
            
            // Check if the parent field exists in the current form level
            if (isset($current->children) && isset($current->children[$parentFieldName])) {
                return $current->children[$parentFieldName];
            }
        }
        
        // If not found, return null (will fall back to original behavior)
        return null;
    }

}
