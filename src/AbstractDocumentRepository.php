<?php

namespace Chaos\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository as BaseDocumentRepository;

/**
 * Class AbstractDocumentRepository.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 *
 * @method $this close() Closes the <tt>DocumentManager</tt>.
 * @method $this flush(array $options = []) Flushes all changes to objects that have been queued up.
 *
 * @property \Doctrine\ODM\MongoDB\Mapping\ClassMetadata $classMetadata The <tt>ClassMetadata</tt> instance.
 * @property \Doctrine\ODM\MongoDB\DocumentManager $documentManager The <tt>DocumentManager</tt> instance.
 * @property array $fieldMappings The field mappings of the class.
 * @property array $identifier The field names that are part of the identifier/primary key of the class.
 */
abstract class AbstractDocumentRepository extends BaseDocumentRepository implements DocumentRepositoryInterface
{
    use DocumentQueryBuilderTrait;

    // <editor-fold defaultstate="collapsed" desc="Magic methods">

    /**
     * {@inheritDoc}
     *
     * @param \Doctrine\ODM\MongoDB\DocumentManager $documentManager The DocumentManager to use.
     * @param \Doctrine\ODM\MongoDB\UnitOfWork $unitOfWork The UnitOfWork to use.
     * @param \Doctrine\ODM\MongoDB\Mapping\ClassMetadata $classMetadata The class metadata.
     */
    public function __construct($documentManager = null, $unitOfWork = null, $classMetadata = null)
    {
        if (isset($documentManager)) {
            parent::__construct($documentManager, $unitOfWork, $classMetadata);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param \Psr\Container\ContainerInterface $container The Container instance.
     * @param object $instance Optional.
     *
     * @return $this
     */
    public function __invoke($container, $instance)
    {
        $this->dm = $container->get('doctrine')->getManagerForClass($this->documentName);
        $this->uow = $this->dm->getUnitOfWork();
        $this->class = $this->dm->getClassMetadata($this->documentName);

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $name The name of the method being called.
     * @param array $arguments An enumerated array containing the parameters passed.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return $this
     */
    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'close':
                $this->dm->close();

                return $this;
            case 'flush':
                if ($this->dm->isOpen()) {
                    $this->dm->flush(@$arguments[0] ?: []);
                }

                return $this;
            default:
                throw new \BadMethodCallException('Invalid magic method access in ' . __CLASS__ . '::__call()');
        }
    }

    /**
     * @param string $name The name of the property being interacted with.
     *
     * @return mixed
     */
    public function __get($name)
    {
        switch ($name) {
            case 'classMetadata':
                return $this->class;
            case 'documentManager':
                return $this->dm;
            case 'fieldMappings':
                return $this->class->fieldMappings;
            case 'identifier':
                return [$this->class->identifier];
            default:
                throw new \InvalidArgumentException('Invalid magic property access in ' . __CLASS__ . '::__get()');
        }
    }

    // </editor-fold>

    /**
     * {@inheritDoc}
     *
     * @param mixed|object|array $criteria The criteria.
     *
     * @return int
     */
    public function count($criteria)
    {
        if (is_scalar($criteria)) {
            $instance = Criteria::create();
            $instance->orWhere($instance->expr()->eq($this->class->identifier, $criteria));
            $criteria = $instance;
        }

        if ($criteria instanceof Criteria) {
            return count($this->matching($criteria));
        }

        return count($this->findBy($criteria));
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options, like: ['autocommit' => true, 'iterations' => 100, 'cleanup' => false]
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return int The affected rows.
     */
    public function create($objects, array $options = ['autocommit' => true])
    {
        if (!is_array($objects)) {
            $objects = [$objects];
        }

        $flushable = !empty($options['autocommit']);
        $iterable = !empty($options['iterations']);
        $counter = 0;

        foreach ($objects as $object) {
            $this->dm->persist($object);

            if ($flushable) {
                $counter++;

                if ($iterable && (0 === $counter % $options['iterations'])) {
                    $counter = 0;
                    $this->dm->flush($options);
                }
            }
        }

        if (!empty($counter)) {
            $this->dm->flush($options);
            empty($options['cleanup']) || $this->dm->clear();
        }

        return count($objects);
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'iterations' => 100, 'cleanup' => false, 'version' => null]
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return int The affected rows.
     */
    public function update($objects, array $options = ['autocommit' => true])
    {
        if (!is_array($objects)) {
            $objects = [$objects];
        }

        $flushable = !empty($options['autocommit']);
        $iterable = !empty($options['iterations']);
        $unlock = empty($options['version']);
        $counter = 0;

        foreach ($objects as $object) {
            $unlock || $this->dm->lock($object, LockMode::OPTIMISTIC, $options['version']);
            $this->dm->merge($object);

            if ($flushable) {
                $counter++;

                if ($iterable && (0 === $counter % $options['iterations'])) {
                    $counter = 0;
                    $this->dm->flush($options);
                }
            }
        }

        if (!empty($counter)) {
            $this->dm->flush($options);
            empty($options['cleanup']) || $this->dm->clear();
        }

        return count($objects);
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'iterations' => 100, 'cleanup' => false, 'version' => null]
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return int The affected rows.
     */
    public function delete($objects, array $options = ['autocommit' => true])
    {
        if (!is_array($objects)) {
            $objects = [$objects];
        }

        $flushable = !empty($options['autocommit']);
        $iterable = !empty($options['iterations']);
        $unlock = empty($options['version']);
        $counter = 0;

        foreach ($objects as $object) {
            if ($this->dm->contains($object)) {
                $unlock || $this->dm->lock($object, LockMode::OPTIMISTIC, $options['version']);
                $this->dm->remove($object);

                if ($flushable) {
                    $counter++;

                    if ($iterable && (0 === $counter % $options['iterations'])) {
                        $counter = 0;
                        $this->dm->flush($options);
                    }
                }
            }
        }

        if (!empty($counter)) {
            $this->dm->flush($options);
            empty($options['cleanup']) || $this->dm->clear();
        }

        return count($objects);
    }
}
