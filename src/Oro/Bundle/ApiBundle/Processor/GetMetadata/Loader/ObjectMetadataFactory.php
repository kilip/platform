<?php

namespace Oro\Bundle\ApiBundle\Processor\GetMetadata\Loader;

use Oro\Bundle\ApiBundle\Config\EntityDefinitionConfig;
use Oro\Bundle\ApiBundle\Config\EntityDefinitionFieldConfig;
use Oro\Bundle\ApiBundle\Metadata\AssociationMetadata;
use Oro\Bundle\ApiBundle\Metadata\EntityMetadata;
use Oro\Bundle\ApiBundle\Metadata\FieldMetadata;
use Oro\Bundle\ApiBundle\Metadata\MetaPropertyMetadata;
use Oro\Bundle\ApiBundle\Request\DataType;
use Oro\Bundle\EntityExtendBundle\Entity\Manager\AssociationManager;
use Oro\Bundle\EntityExtendBundle\Extend\FieldTypeHelper;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;

class ObjectMetadataFactory
{
    /** @var MetadataHelper */
    protected $metadataHelper;

    /** @var AssociationManager */
    protected $associationManager;

    /** @var FieldTypeHelper */
    protected $fieldTypeHelper;

    /**
     * @param MetadataHelper     $metadataHelper
     * @param AssociationManager $associationManager
     * @param FieldTypeHelper    $fieldTypeHelper
     */
    public function __construct(
        MetadataHelper $metadataHelper,
        AssociationManager $associationManager,
        FieldTypeHelper $fieldTypeHelper
    ) {
        $this->metadataHelper = $metadataHelper;
        $this->associationManager = $associationManager;
        $this->fieldTypeHelper = $fieldTypeHelper;
    }

    /**
     * @param string                 $entityClass
     * @param EntityDefinitionConfig $config
     *
     * @return EntityMetadata
     */
    public function createObjectMetadata($entityClass, EntityDefinitionConfig $config)
    {
        $entityMetadata = new EntityMetadata();
        $entityMetadata->setClassName($entityClass);
        $entityMetadata->setIdentifierFieldNames($config->getIdentifierFieldNames());

        return $entityMetadata;
    }

    /**
     * @param EntityMetadata              $entityMetadata
     * @param string                      $entityClass
     * @param string                      $fieldName
     * @param EntityDefinitionFieldConfig $field
     * @param string                      $targetAction
     *
     * @return MetaPropertyMetadata
     */
    public function createAndAddMetaPropertyMetadata(
        EntityMetadata $entityMetadata,
        $entityClass,
        $fieldName,
        EntityDefinitionFieldConfig $field,
        $targetAction
    ) {
        $metaPropertyMetadata = $entityMetadata->addMetaProperty(new MetaPropertyMetadata($fieldName));
        $this->metadataHelper->setPropertyPath($metaPropertyMetadata, $fieldName, $field, $targetAction);
        $metaPropertyMetadata->setDataType(
            $this->metadataHelper->assertDataType($field->getDataType(), $entityClass, $fieldName)
        );

        return $metaPropertyMetadata;
    }

    /**
     * @param EntityMetadata              $entityMetadata
     * @param string                      $entityClass
     * @param string                      $fieldName
     * @param EntityDefinitionFieldConfig $field
     * @param string                      $targetAction
     *
     * @return FieldMetadata
     */
    public function createAndAddFieldMetadata(
        EntityMetadata $entityMetadata,
        $entityClass,
        $fieldName,
        EntityDefinitionFieldConfig $field,
        $targetAction
    ) {
        $fieldMetadata = $entityMetadata->addField(new FieldMetadata($fieldName));
        $this->metadataHelper->setPropertyPath($fieldMetadata, $fieldName, $field, $targetAction);
        $fieldMetadata->setDataType(
            $this->metadataHelper->assertDataType($field->getDataType(), $entityClass, $fieldName)
        );
        $fieldMetadata->setIsNullable(
            !in_array($fieldName, $entityMetadata->getIdentifierFieldNames(), true)
        );

        return $fieldMetadata;
    }

    /**
     * @param EntityMetadata              $entityMetadata
     * @param string                      $entityClass
     * @param string                      $fieldName
     * @param EntityDefinitionFieldConfig $field
     * @param string                      $targetAction
     * @param string|null                 $targetClass
     *
     * @return AssociationMetadata
     */
    public function createAndAddAssociationMetadata(
        EntityMetadata $entityMetadata,
        $entityClass,
        $fieldName,
        EntityDefinitionFieldConfig $field,
        $targetAction,
        $targetClass = null
    ) {
        if (!$targetClass) {
            $targetClass = $field->getTargetClass();
        }
        $associationMetadata = $entityMetadata->addAssociation(new AssociationMetadata($fieldName));
        $this->metadataHelper->setPropertyPath($associationMetadata, $fieldName, $field, $targetAction);
        $associationMetadata->setTargetClassName($targetClass);
        $associationMetadata->setIsNullable(true);
        $associationMetadata->setCollapsed($field->isCollapsed());

        $dataType = $field->getDataType();
        if (!$dataType) {
            $this->setAssociationDataType($associationMetadata, $field);
            $this->setAssociationType($associationMetadata, $field->isCollectionValuedAssociation());
            $associationMetadata->addAcceptableTargetClassName($targetClass);
        } elseif (DataType::isExtendedAssociation($dataType)) {
            list($associationType, $associationKind) = DataType::parseExtendedAssociation($dataType);
            $targets = $this->getExtendedAssociationTargets($entityClass, $associationType, $associationKind);
            $this->setAssociationDataType($associationMetadata, $field);
            $associationMetadata->setAssociationType($associationType);
            $associationMetadata->setAcceptableTargetClassNames(array_keys($targets));
            $associationMetadata->setIsCollection((bool)$field->isCollectionValuedAssociation());
        } elseif (DataType::isExtendedInverseAssociation($dataType)) {
            list($associationSourceClass, $associationType, $associationKind)
                = DataType::parseExtendedInverseAssociation($dataType);
            $associationMetadata->setTargetClassName($associationSourceClass);
            $associationMetadata->setAcceptableTargetClassNames([$associationSourceClass]);
            $reverseType = ExtendHelper::getReverseRelationType(
                $this->fieldTypeHelper->getUnderlyingType($associationType)
            );
            $targets = $this->getExtendedAssociationTargets(
                $associationSourceClass,
                $associationType,
                $associationKind
            );
            $associationMetadata->set(DataType::INVERSE_ASSOCIATION_FIELD, $targets[$entityClass]);
            $associationMetadata->setAssociationType($reverseType);
            $associationMetadata->setIsCollection((bool)$field->isCollectionValuedAssociation());
        } else {
            $associationMetadata->setDataType($dataType);
            $this->setAssociationType($associationMetadata, $field->isCollectionValuedAssociation());
            $associationMetadata->addAcceptableTargetClassName($targetClass);
        }

        return $associationMetadata;
    }

    /**
     * @param string $entityClass
     * @param string $associationType
     * @param string $associationKind
     *
     * @return array [class name => field name, ...]
     */
    protected function getExtendedAssociationTargets($entityClass, $associationType, $associationKind)
    {
        $targets = $this->associationManager->getAssociationTargets(
            $entityClass,
            null,
            $associationType,
            $associationKind
        );

        return $targets;
    }

    /**
     * @param AssociationMetadata         $associationMetadata
     * @param EntityDefinitionFieldConfig $field
     */
    protected function setAssociationDataType(
        AssociationMetadata $associationMetadata,
        EntityDefinitionFieldConfig $field
    ) {
        $targetEntity = $field->getTargetEntity();
        if ($targetEntity) {
            $associationDataType = DataType::STRING;
            $targetIdFieldNames = $targetEntity->getIdentifierFieldNames();
            if (1 === count($targetIdFieldNames)) {
                $targetIdField = $targetEntity->getField(reset($targetIdFieldNames));
                if ($targetIdField) {
                    $associationDataType = $targetIdField->getDataType();
                }
            }
            $associationMetadata->setDataType($associationDataType);
        }
    }

    /**
     * @param AssociationMetadata $associationMetadata
     * @param bool                $isCollection
     */
    protected function setAssociationType(AssociationMetadata $associationMetadata, $isCollection)
    {
        if ($isCollection) {
            $associationMetadata->setAssociationType(RelationType::MANY_TO_MANY);
            $associationMetadata->setIsCollection(true);
        } else {
            $associationMetadata->setAssociationType(RelationType::MANY_TO_ONE);
            $associationMetadata->setIsCollection(false);
        }
    }
}
