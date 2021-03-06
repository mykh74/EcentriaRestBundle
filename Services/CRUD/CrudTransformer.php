<?php
/*
 * This file is part of the ecentria group, inc. software.
 *
 * (c) 2015, ecentria group, inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ecentria\Libraries\EcentriaRestBundle\Services\CRUD;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Ecentria\Libraries\EcentriaRestBundle\Annotation\PropertyRestriction;
use Ecentria\Libraries\EcentriaRestBundle\Model\CRUD\CrudEntityInterface;
use Ecentria\Libraries\EcentriaRestBundle\Validator\Constraints\UniqueEntity;
use JMS\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use JMS\Serializer\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\RecursiveValidator;

/**
 * CRUD Transformer
 *
 * @author Sergey Chernecov <sergey.chernecov@intexsys.lv>
 */
class CrudTransformer
{
    /**
     * Entity manager
     *
     * @var EntityManager
     */
    private $entityManager;

    /**
     * Annotation reader
     *
     * @var AnnotationReader
     */
    private $annotationsReader;

    /**
     * Serializer
     *
     * @var Serializer
     */
    private $serializer;

    /**
     * Class metadata
     *
     * @var ClassMetadata
     */
    private $classMetadata;

    /**
     * Constructor
     *
     * @param EntityManager      $entityManager     entityManager
     * @param AnnotationReader   $annotationsReader annotationsReader
     * @param Serializer         $serializer        serializer
     * @param RecursiveValidator $validator         validator
     */
    public function __construct(
        EntityManager $entityManager,
        AnnotationReader $annotationsReader,
        Serializer $serializer,
        RecursiveValidator $validator
    ) {
        $this->entityManager = $entityManager;
        $this->annotationsReader = $annotationsReader;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Array to object transformation
     *
     * @param array  $data  Object fields
     * @param string $class Class name of object to create
     * @return mixed
     * @throws ConstraintViolation
     */
    public function arrayToObject(array $data, $class)
    {
        $this->initializeClassMetadata($class);
        $object = new $class();
        foreach ($data as $property => $value) {
            $this->processPropertyValue(
                $object,
                $property,
                $value,
                PropertyRestriction::CREATE
            );
        }
        return $object;
    }

    /**
     * Array to object property validation
     *
     * @param array  $data  Object fields
     * @param string $class Class name of object to test
     * @return ConstraintViolationList
     */
    public function arrayToObjectPropertyValidation(array $data, $class)
    {
        $badProperties = new ConstraintViolationList();
        foreach ($data as $property => $value) {
            if (!$this->isPropertyAccessible($property, PropertyRestriction::CREATE)) {
                $badProperties->add(
                    new ConstraintViolation(
                        "This is not a valid property of $class",
                        "This is not a valid property of $class",
                        array(),
                        null,
                        $property,
                        $value
                    )
                );
            }
        }
        return $badProperties;
    }

    /**
     * Array to object collection transformation
     *
     * @param array  $data  Array of individual object field arrays
     * @param string $class Given collection class name of object to create
     *
     * @return ArrayCollection
     */
    public function arrayToCollection(array $data, $class)
    {
        $collection = new ArrayCollection();
        if (is_array($data)) {
            $this->initializeClassMetadata($class);
            foreach ($data as $item) {
                $object = new $class();
                foreach ($item as $property => $value) {
                    $this->processPropertyValue(
                        $object,
                        $property,
                        $value,
                        PropertyRestriction::CREATE,
                        $collection
                    );
                }
                $collection->add($object);
            }
        }
        return $collection;
    }

    /**
     * Initializing class metadata
     *
     * @param string $className className
     *
     * @return void
     */
    public function initializeClassMetadata($className)
    {
        $this->classMetadata = $this->entityManager->getClassMetadata($className);
    }

    /**
     * GetUniqueSearchConditions
     *
     * @param CrudEntityInterface $entity entity
     *
     * @throws \Exception
     *
     * @return array
     */
    public function getUniqueSearchConditions(CrudEntityInterface $entity)
    {
        $fields = [];

        $annotation = $this->annotationsReader->getClassAnnotation(
            $this->getClassMetadata()->getReflectionClass(),
            'Ecentria\Libraries\EcentriaRestBundle\Validator\Constraints\UniqueEntity'
        );

        if (!$annotation) {
            return $fields;
        }

        /** @var UniqueEntity $annotation */
        foreach ($annotation->fields as $field) {
            $getter = $this->getPropertyGetter($field);
            $fields[$field] = $entity->$getter();
        }

        return $fields;
    }

    /**
     * Is property transform granted
     *
     * @param string $property property
     * @param string $action   action
     *
     * @return bool
     */
    public function isPropertyAccessible($property, $action)
    {
        $property = Inflector::camelize($property);
        if ($this->getClassMetadata()->hasAssociation(ucfirst($property))) {
            $property = ucfirst($property);
        }

        if (!$this->getClassMetadata()->hasField($property) && !$this->getClassMetadata()->hasAssociation($property)) {
            return false;
        }

        $propertyRestriction = $this->annotationsReader->getPropertyAnnotation(
            $this->getClassMetadata()->getReflectionProperty($property),
            PropertyRestriction::NAME
        );
        if ($propertyRestriction instanceof PropertyRestriction) {
            return $propertyRestriction->isGranted($action);
        }
        return true;
    }

    /**
     * Transform property value
     *
     * @param string          $property   property
     * @param mixed           $value      value
     * @param ArrayCollection $collection collection
     *
     * @return object
     */
    public function transformPropertyValue($property, $value, ArrayCollection $collection = null)
    {
        if ($this->transformationNeeded($property, $value)) {
            $targetClass = $this->getClassMetadata()->getAssociationTargetClass(ucfirst($property));

            if (is_null($collection)) {
                $value = $this->processValue($value, $targetClass);
            } else {
                $object = $this->findByIdentifier($collection, $value);
                if (is_null($object)) {
                    $object = $this->processValue($value, $targetClass);
                }
                $value = $object;
            }
        }
        return $value;
    }

    /**
     * Processing mixed value
     *
     * @param mixed  $value       value
     * @param string $targetClass targetClass
     *
     * @return mixed
     */
    private function processValue($value, $targetClass)
    {
        if (is_array($value)) {
            $value = $this->processArrayValue($value, $targetClass);
        } else {
            $value = $this->entityManager->getReference($targetClass, $value);
        }
        return $value;
    }

    /**
     * Process array value
     *
     * @param mixed  $value       value
     * @param string $targetClass targetClass
     *
     * @return array|mixed|null|object
     */
    private function processArrayValue(array $value, $targetClass)
    {
        $deserializedValue = $this->serializer->deserialize(
            json_encode($value),
            $targetClass,
            'json'
        );

        if ($deserializedValue->getId()) {
            $value = $this->entityManager->find($targetClass, $deserializedValue->getId());
        }

        if (!$value || !$deserializedValue->getId()) {
            $value = $deserializedValue;
        }
        return $value;
    }

    /**
     * Getter for property setter
     *
     * @param string $property property
     *
     * @return string
     */
    public function getPropertySetter($property)
    {
        return Inflector::camelize('set_' . $property);
    }

    /**
     * Getter for property getter
     *
     * @param string $property property
     *
     * @return string
     */
    public function getPropertyGetter($property)
    {
        return Inflector::camelize('get_' . $property);
    }

    /**
     * Processing property value
     *
     * @param object               $object     object
     * @param string               $property   property
     * @param mixed                $value      value
     * @param string               $action     action
     * @param ArrayCollection|null $collection collection
     *
     * @return void
     */
    public function processPropertyValue($object, $property, $value, $action, ArrayCollection $collection = null)
    {
        if (!$this->isPropertyAccessible($property, $action)) {
            return;
        }
        $value = $this->transformPropertyValue($property, $value, $collection);
        $method = $this->getPropertySetter($property);
        if (method_exists($object, $method)) {
            $object->$method($value);
        }
    }

    /**
     * FindByIdentifier
     *
     * @param ArrayCollection $collection collection
     * @param mixed           $value      value
     *
     * @return null|object
     */
    private function findByIdentifier(ArrayCollection $collection, $value)
    {
        $object = null;
        foreach ($collection as $collectionItem) {
            $property = $this->getClassMetadata()->getSingleIdentifierFieldName();
            $method = $this->getPropertyGetter($property);
            if ($collectionItem->$method() === $value) {
                $object = $collectionItem;
            }
        }
        return $object;
    }

    /**
     * Transformation needed?
     *
     * @param string $property property
     * @param mixed  $value    value
     *
     * @return bool
     */
    private function transformationNeeded($property, $value)
    {
        return is_null($value) ? false : $this->getClassMetadata()->hasAssociation(ucfirst($property));
    }

    /**
     * ClassMetadata getter
     *
     * @throws \Exception
     * @return ClassMetadata
     */
    private function getClassMetadata()
    {
        if (!$this->classMetadata instanceof ClassMetadata) {
            throw new \Exception('You forgot to call initializeClassMetadata method.');
        }
        return $this->classMetadata;
    }
}
