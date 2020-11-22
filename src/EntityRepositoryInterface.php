<?php

namespace Chaos\Repository;

use Doctrine\Persistence\ObjectRepository;

/**
 * Interface EntityRepositoryInterface.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 */
interface EntityRepositoryInterface extends ObjectRepository, RepositoryInterface
{
    /**
     * The default `paginate` method, you can override this in the derived class.
     *
     * @param array $criteria The criteria.
     * @param bool $fetchJoinCollection Optional.
     *
     * @return \Doctrine\ORM\Tools\Pagination\Paginator
     */
    public function paginate(array $criteria, $fetchJoinCollection = true);

    /**
     * The default `search` method, you can override this in the derived class.
     *
     * @param array $criteria The criteria.
     *
     * @return \ArrayIterator
     */
    public function search(array $criteria);

    /**
     * The default `read` method, you can override this in the derived class.
     *
     * @param array $criteria The criteria.
     *
     * @return null|object
     */
    public function read(array $criteria);
}
