<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineMongoDBAdminBundle\Builder;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\FieldDescriptionInterface;
use Sonata\AdminBundle\Builder\FormContractorInterface;
use Sonata\AdminBundle\Form\Type\AdminType;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Form\Type\ModelHiddenType;
use Sonata\AdminBundle\Form\Type\ModelListType;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\AdminBundle\Form\Type\ModelTypeList;
use Sonata\DoctrineMongoDBAdminBundle\Model\ModelManager;
use Sonata\Form\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * @final since sonata-project/doctrine-mongodb-admin-bundle 3.5.
 */
class FormContractor implements FormContractorInterface
{
    /**
     * @deprecated since version 3.1.0, to be removed in 4.0
     *
     * @var FormFactoryInterface
     */
    protected $fieldFactory;

    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    public function __construct(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    /**
     * @return void
     */
    public function fixFieldDescription(AdminInterface $admin, FieldDescriptionInterface $fieldDescription)
    {
        // NEXT_MAJOR: Remove the following 2 lines.
        $modelManager = $admin->getModelManager();
        \assert($modelManager instanceof ModelManager);

        // NEXT_MAJOR: Remove this block.
        if ($modelManager->hasMetadata($admin->getClass(), 'sonata_deprecation_mute')) {
            $metadata = $modelManager->getMetadata($admin->getClass(), 'sonata_deprecation_mute');

            // set the default field mapping
            if (isset($metadata->fieldMappings[$fieldDescription->getName()])) {
                $fieldDescription->setFieldMapping($metadata->fieldMappings[$fieldDescription->getName()]);
            }

            // set the default association mapping
            if (isset($metadata->associationMappings[$fieldDescription->getName()])) {
                $fieldDescription->setAssociationMapping($metadata->associationMappings[$fieldDescription->getName()]);
            }
        }

        if (!$fieldDescription->getType()) {
            throw new \RuntimeException(sprintf(
                'Please define a type for field `%s` in `%s`',
                $fieldDescription->getName(),
                \get_class($admin)
            ));
        }

        $fieldDescription->setAdmin($admin);
        $fieldDescription->setOption('edit', $fieldDescription->getOption('edit', 'standard'));

        if (\in_array($fieldDescription->getMappingType(), [ClassMetadata::ONE, ClassMetadata::MANY], true)) {
            $admin->attachAdminClass($fieldDescription);
        }
    }

    /**
     * @return FormFactoryInterface
     */
    public function getFormFactory()
    {
        return $this->formFactory;
    }

    public function getFormBuilder($name, array $formOptions = [])
    {
        return $this->getFormFactory()->createNamedBuilder($name, FormType::class, null, $formOptions);
    }

    public function getDefaultOptions($type, FieldDescriptionInterface $fieldDescription)
    {
        // NEXT_MAJOR: Remove this line and update the function signature.
        $formOptions = \func_get_args()[2] ?? [];

        $options = [];
        $options['sonata_field_description'] = $fieldDescription;

        if ($this->checkFormClass($type, [
            ModelType::class,
            ModelTypeList::class,
            ModelListType::class,
            ModelHiddenType::class,
            ModelAutocompleteType::class,
        ])) {
            if ('list' === $fieldDescription->getOption('edit')) {
                throw new \LogicException(
                    'The ``Sonata\AdminBundle\Form\Type\ModelType`` type does not accept an ``edit`` option anymore,'
                    .' please review the UPGRADE-2.1.md file from the SonataAdminBundle'
                );
            }

            $options['class'] = $fieldDescription->getTargetModel();
            $options['model_manager'] = $fieldDescription->getAdmin()->getModelManager();

            if ($this->checkFormClass($type, [ModelAutocompleteType::class])) {
                if (!$fieldDescription->getAssociationAdmin()) {
                    throw new \RuntimeException(sprintf(
                        'The current field `%s` is not linked to an admin.'
                        .' Please create one for the target entity: `%s`',
                        $fieldDescription->getName(),
                        $fieldDescription->getTargetModel()
                    ));
                }
            }
        } elseif ($this->checkFormClass($type, [AdminType::class])) {
            if (!$fieldDescription->getAssociationAdmin()) {
                throw new \RuntimeException(sprintf(
                    'The current field `%s` is not linked to an admin.'
                    .' Please create one for the target entity : `%s`',
                    $fieldDescription->getName(),
                    $fieldDescription->getTargetModel()
                ));
            }

            if (!\in_array($fieldDescription->getMappingType(), [ClassMetadata::ONE, ClassMetadata::MANY], true)) {
                throw new \RuntimeException(sprintf(
                    'You are trying to add `%s` field `%s` which is not One-To-One or Many-To-One.'
                    .' Maybe you want `%s` instead?',
                    AdminType::class,
                    $fieldDescription->getName(),
                    CollectionType::class
                ));
            }

            // set sensitive default value to have a component working fine out of the box
            $options['btn_add'] = false;
            $options['delete'] = false;

            $options['data_class'] = $fieldDescription->getAssociationAdmin()->getClass();
            $fieldDescription->setOption('edit', $fieldDescription->getOption('edit', 'admin'));
        } elseif ($this->checkFormClass($type, [
            CollectionType::class,
            // NEXT_MAJOR: Remove 'Sonata\CoreBundle\Form\Type\CollectionType'
            'Sonata\CoreBundle\Form\Type\CollectionType',
        ])) {
            if (!$fieldDescription->getAssociationAdmin()) {
                throw new \RuntimeException(sprintf(
                    'The current field `%s` is not linked to an admin.'
                    .' Please create one for the target entity : `%s`',
                    $fieldDescription->getName(),
                    $fieldDescription->getTargetModel()
                ));
            }

            $options['type'] = AdminType::class;
            $options['modifiable'] = true;
            $options['type_options'] = $this->getDefaultAdminTypeOptions($fieldDescription, $formOptions);
        }

        return $options;
    }

    /**
     * @param string $type
     * @param array  $classes
     *
     * @return array
     */
    private function checkFormClass($type, $classes)
    {
        return array_filter($classes, static function ($subclass) use ($type) {
            return is_a($type, $subclass, true);
        });
    }

    private function getDefaultAdminTypeOptions(FieldDescriptionInterface $fieldDescription, array $formOptions): array
    {
        $typeOptions = [
            'sonata_field_description' => $fieldDescription,
            'data_class' => $fieldDescription->getAssociationAdmin()->getClass(),
            'empty_data' => static function () use ($fieldDescription) {
                return $fieldDescription->getAssociationAdmin()->getNewInstance();
            },
        ];

        if (isset($formOptions['by_reference'])) {
            $typeOptions['collection_by_reference'] = $formOptions['by_reference'];
        }

        return $typeOptions;
    }
}
