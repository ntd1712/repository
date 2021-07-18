<?php

namespace Chaos\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository as BaseEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Class AbstractEntityRepository.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 *
 * @method $this close() Closes the <tt>EntityManager</tt>.
 * @method $this flush() Flushes all changes to objects that have been queued up.
 * @method $this beginTransaction() Starts a transaction.
 * @method $this commit() Commits the current transaction.
 * @method $this rollBack() Rolls back the current transaction.
 * @method mixed transactional($func) Executes a function in a transaction.
 *
 * @property \Doctrine\ORM\Mapping\ClassMetadata $classMetadata The <tt>ClassMetadata</tt> instance.
 * @property \Doctrine\ORM\EntityManager $entityManager The <tt>EntityManager</tt> instance.
 * @property array $fieldMappings The field mappings of the class.
 * @property array $identifier The field names that are part of the identifier/primary key of the class.
 */
abstract class AbstractEntityRepository extends BaseEntityRepository implements EntityRepositoryInterface
{
    use EntityQueryBuilderTrait;

    // <editor-fold defaultstate="collapsed" desc="Magic methods">

    /**
     * {@inheritDoc}
     *
     * @param \Doctrine\ORM\EntityManagerInterface $entityManager The EntityManager to use.
     * @param \Doctrine\ORM\Mapping\ClassMetadata $classMetadata The class metadata.
     */
    public function __construct($entityManager = null, $classMetadata = null)
    {
        if (isset($entityManager)) {
            parent::__construct($entityManager, $classMetadata);
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
        $this->_em = $container->get('doctrine')->getManagerForClass($this->_entityName);
        $this->_class = $this->_em->getClassMetadata($this->_entityName);

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $name The name of the method being called.
     * @param array $arguments An enumerated array containing the parameters passed.
     *
     * @throws \Doctrine\DBAL\ConnectionException
     * @throws \Throwable
     *
     * @return $this|mixed
     */
    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'close':
                $this->_em->close();

                return $this;
            case 'flush':
                if ($this->_em->isOpen()) {
                    $this->_em->flush();
                }

                return $this;
            case 'commit':
                $connection = $this->_em->getConnection();

                if ($connection->isTransactionActive() && !$connection->isRollbackOnly()) {
                    $this->_em->commit();
                }

                return $this;
            case 'transactional':
                return $this->_em->transactional(@$arguments[0]);
            default:
                if (0 === strpos($name, 'beginTransaction')) {
                    $this->_em->beginTransaction();

                    return $this;
                }

                if (0 === strpos($name, 'rollBack')) {
                    if ($this->_em->getConnection()->isTransactionActive()) {
                        $this->_em->rollBack();
                    }

                    return $this;
                }

                return parent::__call($name, $arguments);
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
                return $this->_class;
            case 'entityManager':
                return $this->_em;
            case 'fieldMappings':
                $fieldMappings = $this->_class->fieldMappings;
                unset( // stop searching audit fields
                    $fieldMappings['CreatedBy'],
                    $fieldMappings['UpdatedBy'],
                    $fieldMappings['DeletedBy'],
                    $fieldMappings['AppKey']
                );

                return $fieldMappings;
            case 'identifier':
                return $this->_class->identifier;
            default:
                throw new \InvalidArgumentException('Invalid magic property access in ' . __CLASS__ . '::__get()');
        }
    }

    // </editor-fold>

    /**
     * {@inheritDoc}
     *
     * @param array $criteria The criteria.
     * @param bool $fetchJoinCollection Optional.
     *
     * @return \Doctrine\ORM\Tools\Pagination\Paginator
     */
    public function paginate(array $criteria, $fetchJoinCollection = true)
    {
        $query = $this->getQueryBuilder($criteria)
            ->getQuery();

        return new Paginator($query, $fetchJoinCollection);
    }

    /**
     * {@inheritDoc}
     *
     * @param array $criteria The criteria.
     *
     * @return \ArrayIterator
     */
    public function search(array $criteria)
    {
        $query = $this->getQueryBuilder($criteria)
            ->getQuery();

        return new \ArrayIterator($query->execute());
    }

    /**
     * {@inheritDoc}
     *
     * @param array $criteria The criteria.
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return null|object
     */
    public function read(array $criteria)
    {
        $query = $this->getQueryBuilder($criteria)
            ->setMaxResults(1)
            ->getQuery();

        return $query->getOneOrNullResult();
    }

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

            foreach ($this->_class->identifier as $identifier) {
                $instance->orWhere($instance->expr()->eq($identifier, $criteria));
            }

            $criteria = $instance;
        }

        if ($criteria instanceof Criteria) {
            return count($this->matching($criteria));
        }

        return parent::count($criteria);
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options, like: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100]
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\Persistence\Mapping\MappingException
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
            $this->_em->persist($object);

            if ($flushable) {
                $counter++;

                if ($iterable && (0 === $counter % $options['iterations'])) {
                    $counter = 0;
                    $this->_em->flush();
                }
            }
        }

        if (!empty($counter)) {
            $this->_em->flush();
            empty($options['cleanup']) || $this->_em->clear();
        }

        return count($objects);
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100, 'version' => null]
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\Persistence\Mapping\MappingException
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
            $unlock || $this->_em->lock($object, LockMode::OPTIMISTIC, $options['version']);
            $this->_em->merge($object);

            if ($flushable) {
                $counter++;

                if ($iterable && (0 === $counter % $options['iterations'])) {
                    $counter = 0;
                    $this->_em->flush();
                }
            }
        }

        if (!empty($counter)) {
            $this->_em->flush();
            empty($options['cleanup']) || $this->_em->clear();
        }

        return count($objects);
    }

    /**
     * {@inheritDoc}
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100, 'version' => null]
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\Persistence\Mapping\MappingException
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
            if ($this->_em->contains($object)) {
                $unlock || $this->_em->lock($object, LockMode::OPTIMISTIC, $options['version']);
                $this->_em->remove($object);

                if ($flushable) {
                    $counter++;

                    if ($iterable && (0 === $counter % $options['iterations'])) {
                        $counter = 0;
                        $this->_em->flush();
                    }
                }
            }
        }

        if (!empty($counter)) {
            $this->_em->flush();
            empty($options['cleanup']) || $this->_em->clear();
        }

        return count($objects);
    }
}
