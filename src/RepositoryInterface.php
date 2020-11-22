<?php

namespace Chaos\Repository;

/**
 * Interface RepositoryInterface.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 */
interface RepositoryInterface
{
    /**
     * The default `count` method, you can override this in the derived class.
     *
     * <code>
     * $this->repository->count('keyword');
     * $this->repository->count(
     *   Criteria::create()->where(
     *     Criteria::create()->expr()->eq('Name', 'keyword')
     *   )
     * );
     * $this->repository->count(['Name' => 'keyword']);
     * </code>
     *
     * @param mixed|object|array $criteria The criteria.
     *
     * @return int
     */
    public function count($criteria);

    /**
     * The default `create` method, you can override this in the derived class.
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options, like: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100]
     *
     * @return int The affected rows.
     */
    public function create($objects, array $options = []);

    /**
     * The default `update` method, you can override this in the derived class.
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100, 'version' => null]
     *
     * @return int The affected rows.
     */
    public function update($objects, array $options = []);

    /**
     * The default `delete` method, you can override this in the derived class.
     *
     * @param object[]|object $objects Either an array of objects, or a single object.
     * @param array $options Options: ['autocommit' => true, 'cleanup' => false, 'iterations' => 100, 'version' => null]
     *
     * @return int The affected rows.
     */
    public function delete($objects, array $options = []);
}
