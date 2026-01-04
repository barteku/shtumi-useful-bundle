<?php

namespace Shtumi\UsefulBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DependentFilteredValueType extends AbstractType
{
    private $filteredValues;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->filteredValues = $parameterBag->get('shtumi.dependent_filtered_values');
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'entity_alias' => null,
            'parent_field' => null,
            'compound' => false,
        ]);

        $resolver->setRequired(['entity_alias', 'parent_field']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'shtumi_dependent_filtered_value';
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $values = $this->filteredValues;
        
        if (!isset($values[$options['entity_alias']])) {
            throw new \InvalidArgumentException(sprintf('Entity alias "%s" is not configured in dependent_filtered_values.', $options['entity_alias']));
        }

        $valueConfig = $values[$options['entity_alias']];
        
        $builder->setAttribute("parent_field", $options['parent_field']);
        $builder->setAttribute("entity_alias", $options['entity_alias']);
        $builder->setAttribute("entity_config", $valueConfig);
    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['parent_field'] = $form->getConfig()->getAttribute('parent_field');
        $view->vars['entity_alias'] = $form->getConfig()->getAttribute('entity_alias');
        $view->vars['entity_config'] = $form->getConfig()->getAttribute('entity_config');
        
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

