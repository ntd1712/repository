<?php

namespace Chaos\Repository;

use Doctrine\ODM\MongoDB\Query\Builder as QueryBuilder;

use const Chaos\PREG_PATTERN_COMMA_SEPARATOR;

/**
 * Class DocumentQueryBuilderTrait.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 */
trait DocumentQueryBuilderTrait
{
    /**
     * Creates a <tt>QueryBuilder</tt> object.
     *
     * @param array $criteria The criteria.
     * @param null|\Doctrine\ODM\MongoDB\Query\Builder $queryBuilder Optional.
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getQueryBuilder(array $criteria, QueryBuilder $queryBuilder = null)
    {
        if (null === $queryBuilder) {
            $queryBuilder = $this->createQueryBuilder();
        }

        if (empty($criteria)) {
            return $queryBuilder;
        }

        foreach ($criteria as $k => $v) {
            switch ($k) {
                case 'select':
                    // e.g. ['select' => 'username, password']
                    //      ['select' => ['username', 'password']]
                    if (is_string($v)) {
                        $v = preg_split(PREG_PATTERN_COMMA_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                    } elseif (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    $queryBuilder->select($v);
                    break;
                case 'type':
                    // count()
                    // distinct(string $field)
                    // find(?string $documentName = null)
                    // findAndRemove(?string $documentName = null)
                    // findAndUpdate(?string $documentName = null)
                    // insert(?string $documentName = null)
                    // remove(?string $documentName = null)
                    // updateOne(?string $documentName = null)
                    // updateMany(?string $documentName = null)
                    break;
                case 'distinct':
                    $queryBuilder->distinct($v);
                    break;
                case 'hint':
                case 'field':
                    // where($javascript)
                    // in($values)
                    // notIn($values)
                    // equals($value)
                    // notEqual($value)
                    // gt($value)
                    // gte($value)
                    // lt($value)
                    // lte($value)
                    // range($start, $end)
                    // size($size)
                    // exists($bool)
                    // type($type)
                    // all($values)
                    // mod($mod)
                    // addOr($expr)
                    // references($document)
                    // includesReferenceTo($document)
                case 'refresh':
                case 'readOnly':
                case 'hydrate':
                case 'limit':
                case 'skip':
                case 'sort':
                case 'immortal':
                case 'maxTimeMS':
                    break;
                default:
            }
        }

        return $queryBuilder;
    }
}
