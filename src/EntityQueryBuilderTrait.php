<?php

namespace Chaos\Repository;

use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Laminas\Db\Sql\Predicate\PredicateInterface;

use function Chaos\guessNamespace;
use function Chaos\shorten;

use const Chaos\PREG_PATTERN_ASC_DESC;
use const Chaos\PREG_PATTERN_COMMA_SEPARATOR;
use const Chaos\PREG_PATTERN_EQUALITY_SEPARATOR;
use const Chaos\PREG_PATTERN_SPACE_SEPARATOR;

/**
 * Class EntityQueryBuilderTrait.
 *
 * @author t(-.-t) <ntd1712@mail.com>
 */
trait EntityQueryBuilderTrait
{
    // <editor-fold defaultstate="collapsed" desc="Default properties">

    /**
     * @var string[]
     */
    private static $joinsMap = [
        'join' => 'join',
        'innerJoin' => 'innerJoin',
        'leftJoin' => 'leftJoin'
    ];

    // </editor-fold>

    /**
     * Creates a SELECT <tt>QueryBuilder</tt> object.
     *
     * <code>
     * $expr = $this->_em->getExpressionBuilder();
     * $criteria = [
     *   'select' => [
     *     ['from' => 'User', 'alias' => 'u'],
     *     ['from' => $this->roleRepository]
     *   ],
     *   'distinct' => false,
     *   'joins' => [
     *     ['join'      => 'UserRole'],
     *     ['innerJoin' => $this->roleRepository, 'condition' => '%3$s = %2$s.%3$s'],
     *     ['leftJoin'  => 'Entity\Address', 'alias' => 'a', 'condition' => '%1$s = %4$s.%1$s', 'conditionType' => 'ON']
     *   ],
     *   'where'  => $expr->orX($expr->in('u.Id', ':id'), $expr->like('Role.Name', ':name')),
     * # 'group'  => 'Id, %2$s.Name',
     *   'having' => $expr->andX($expr->neq('NotUse', '?1'), $expr->gt('Role.Id', '?2')),
     *   'order'  => ['Id' => 'DESC', '%2$s.Name'],
     *   'limit'  => 10,
     *   'offset' => 0
     * ];
     *
     * echo $this->getQueryBuilder($criteria);
     * </code>
     *
     * @param array $criteria The criteria.
     * @param null|\Doctrine\ORM\QueryBuilder $queryBuilder Optional.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getQueryBuilder(array $criteria, QueryBuilder $queryBuilder = null)
    {
        if (null === $queryBuilder) {
            $queryBuilder = $this->createQueryBuilder($this->_class->reflClass->getShortName());
        }

        if (empty($criteria)) {
            return $queryBuilder;
        }

        $aliases = $queryBuilder->getAllAliases();

        foreach ($criteria as $k => $v) {
            switch ($k) {
                case 'select':
                    // e.g. ['select' => 'User u INDEX BY u.Id, Role']
                    //      ['select' => [
                    //        ['from' => 'User', 'alias' => 'u', 'indexBy' => 'u.Id'],
                    //        ['from' => 'Role'] // equivalent to ['alias' => 'Role', 'indexBy' => null]
                    //      ]]
                    //      ['select' => [
                    //        ['from' => $this->userRepository, 'alias' => 'u', 'indexBy' => 'u.Id'],
                    //        ['from' => $this->roleRepository]
                    //      ]]
                    //      ['select' => [
                    //        new Expr\From('User', 'u', 'u.Id'),
                    //        new Expr\From('Role', 'Role')
                    //      ]]
                    if (is_string($v)) {
                        $v = preg_split(PREG_PATTERN_COMMA_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                    } elseif (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    $queryBuilder->resetDQLParts(['select', 'from']);

                    foreach ($v as $from) {
                        if (is_string($from)) {
                            $parts = preg_split(PREG_PATTERN_SPACE_SEPARATOR, $from, -1, PREG_SPLIT_NO_EMPTY);
                            $from = [
                                'from' => $parts[0],
                                'alias' => isset($parts[1]) ? $parts[1] : null,
                                'indexBy' => isset($parts[4]) ? $parts[4] : null
                            ];
                        } elseif ($from instanceof Expr\From) {
                            $from = [
                                'from' => $from->getFrom(),
                                'alias' => $from->getAlias(),
                                'indexBy' => $from->getIndexBy()
                            ];
                        } elseif (!is_array($from) || empty($from['from'])) {
                            throw new \InvalidArgumentException(
                                __METHOD__ . " expects '{$k}' in array format and its required key 'from'"
                            );
                        }

                        if ($from['from'] instanceof ObjectRepository) {
                            $from['from'] = $from['from']->getClassName();
                        } elseif (false === strpos($from['from'], '\\')) {
                            $from['from'] = guessNamespace($from['from'], $this->_class->namespace);
                        }

                        if (empty($from['alias'])) {
                            $from['alias'] = shorten($from['from']);
                        }

                        if (empty($from['indexBy'])) {
                            $from['indexBy'] = null;
                        }

                        $queryBuilder->from($from['from'], $from['alias'], $from['indexBy']);
                    }

                    $queryBuilder->select($aliases = $queryBuilder->getAllAliases());
                    break;
                case 'joins':
                    // e.g. ['joins' => [
                    //        ['join'      => 'UserRole', 'condition' => '%1$s = %2$s.%1$s'],
                    //        ['innerJoin' => $this->roleRepository, 'condition' => '%3$s = %2$s.%3$s'],
                    //        ['leftJoin'  => 'Entity\Address', 'alias' => 'a', 'condition' => '%1$s = %4$s.%1$s',
                    //                        'conditionType' => 'ON', 'indexBy' => 'a.StreetName']
                    //      ]]
                    //      # User INNER JOIN UserRole WITH User = UserRole.User
                    //      #      INNER JOIN MyApplication\Entity\Role WITH Role = UserRole.Role
                    //      #      LEFT JOIN Entity\Address a INDEX BY a.StreetName ON User = a.User
                    if (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    if (!isset($v[0])) {
                        $v = [$v];
                    }

                    foreach ($v as $join) {
                        if (!is_array($join) || !isset(self::$joinsMap[$type = key($join)])) {
                            throw new \InvalidArgumentException(
                                __METHOD__ . " expects '{$k}' in array format" . ' and its required key "join"'
                            );
                        }

                        if ($join[$type] instanceof ObjectRepository) {
                            $join[$type] = $join[$type]->getClassName();
                        }

                        if (empty($join['alias'])) {
                            $join['alias'] = shorten($join[$type]);
                        }

                        $aliases[] = $join['alias'];
                        $format = isset($join['condition']) // guess condition
                            ? $join['condition']
                            : '%1$s = %' . (array_search($join['alias'], $aliases) + 1) . '$s.%1$s';

                        if (false !== ($condition = @vsprintf($format, $aliases))) {
                            $join['condition'] = $condition;
                        } else {
                            continue;
                        }

                        if (
                            empty($join['conditionType'])
                            || 'ON' !== ($join['conditionType'] = strtoupper($join['conditionType']))
                        ) {
                            $join['conditionType'] = 'WITH';
                        }

                        if (empty($join['indexBy'])) {
                            $join['indexBy'] = null;
                        }

                        /* @see \Doctrine\ORM\QueryBuilder::join */
                        /* @see \Doctrine\ORM\QueryBuilder::innerJoin */
                        /* @see \Doctrine\ORM\QueryBuilder::leftJoin */
                        call_user_func(
                            [$queryBuilder, $type],
                            $join[$type],
                            $join['alias'],
                            $join['conditionType'],
                            $join['condition'],
                            $join['indexBy']
                        );
                    }
                    break;
                case 'where':
                    // e.g. ['where' => '%1$s.AppKey = ?1 AND (%2$s.Name = ?2 OR %2$s.Name LIKE ?2)']
                    //      ['where' => ['AppKey' => ':appKey', '%2$s.Name' => ':name']]
                    //      ['where' => ['Id' => [1, 2], $expr->eq('Role.Name', ':name')]]
                    //      ['where' => [
                    //        'Id' => [
                    //          'array' => [$obj1, $obj2],  // $obj1 = (object)['Id' => 1]; $obj2 = (object)['Id' => 2];
                    //          'column_key' => 'Id'
                    //        ],
                    //        '%2$s.Name' => ':name'
                    //      ]]
                    //      ['where' => $expr->orX(
                    //        $expr->in('u.AppKey', '?1'),
                    //        $expr->eq('Role.Name', '?2')
                    //      )]
                    //      ['where' => \Laminas\Db\Sql\Predicate\Predicate $predicate])]
                    if ($v instanceof PredicateInterface) { // TODO: check 'm
                        $this->resolvePredicate($v, $queryBuilder, $aliases);
                        break;
                    }

                    if (is_array($v)) {
                        $args = [];
                        $expr = $queryBuilder->expr();

                        foreach ($v as $key => $value) {
                            if (is_string($key)) {
                                if (false === ($key = $this->formatName($key, $aliases))) {
                                    continue;
                                }

                                if ($isArray = is_array($value)) {
                                    if (!(empty($value['array']) || empty($value['column_key']))) {
                                        $temp = [];

                                        foreach ((array) $value['array'] as $obj) {
                                            if (!is_object($obj) || !property_exists($obj, $value['column_key'])) {
                                                continue;
                                            }

                                            $temp[] = $obj->{$value['column_key']};
                                        }

                                        $value = $temp;
                                    }
                                }

                                $args[] = $isArray ? $expr->in($key, $value) : $expr->eq($key, $value);
                            } else {
                                $args[] = $value;
                            }
                        }

                        if (empty($args)) {
                            break;
                        }

                        $v = new Expr\Andx($args);
                    } elseif (is_string($v)) {
                        if (false !== ($string = @vsprintf($v, $aliases))) {
                            $v = $string;
                        }
                    }

                    $queryBuilder->where($v);
                    break;
                case 'groupBy':
                case 'group':
                    // e.g. ['group' => 'Id, %2$s.Name']
                    //      ['group' => ['Id', 'Name']]
                    if (is_string($v)) {
                        $v = preg_split(PREG_PATTERN_COMMA_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                    } elseif (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    foreach ($v as $group) {
                        if (false !== ($groupBy = $this->formatName($group, $aliases))) {
                            $queryBuilder->addGroupBy($groupBy);
                        }
                    }
                    break;
                case 'having':
                    // e.g. ['having' => 'MAX(%1$s.OpenId) > ?3 AND MIN(%1$s.OpenId) < ?4']
                    //      ['having' => $expr->andX(
                    //        $expr->gt('MAX(u.OpenId)', '?3'),
                    //        $expr->lt('MIN(u.OpenId)', '?4')
                    //      )]
                    if (is_string($v)) {
                        if (false !== ($string = @vsprintf($v, $aliases))) {
                            $v = $string;
                        }
                    }

                    $queryBuilder->having($v);
                    break;
                case 'orderBy':
                case 'order':
                    // e.g. ['order' => 'Id DESC, %2$s.Name']
                    //      ['order' => 'Id DESC NULLS FIRST, %2$s.Name ASC NULLS LAST']
                    //      ['order' => ['Id' => 'DESC NULLS FIRST', '%2$s.Name']]
                    if (is_string($v)) {
                        $v = preg_split(PREG_PATTERN_COMMA_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                    } elseif (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    foreach ($v as $key => $value) {
                        $matches = [];
                        preg_match(PREG_PATTERN_ASC_DESC, is_string($key) ? $key . ' ' . $value : $value, $matches);

                        if (empty($matches[1])) {
                            continue;
                        }

                        if (false === ($sort = $this->formatName($matches[1], $aliases))) {
                            continue;
                        }

                        $order = empty($matches[2]) || 'DESC' !== strtoupper($matches[2]) ? 'ASC' : 'DESC';

                        if (!empty($matches[3])) { // NULLS FIRST, NULLS LAST
                            $order .= ' ' . trim($matches[3]);
                        }

                        $queryBuilder->addOrderBy($sort, $order);
                    }
                    break;
                case 'limit':
                    $queryBuilder->setMaxResults($v);
                    break;
                case 'offset':
                    $queryBuilder->setFirstResult($v);
                    break;
                case 'cacheable':
                case 'cacheRegion':
                case 'cacheMode':
                case 'lifetime':
                case 'firstResult':
                case 'maxResults':
                case 'parameters':
                    $queryBuilder->{'set' . ucfirst($k)}($v);
                    break;
                case 'distinct':
                case 'quantifier':
                    // e.g. ['distinct' => true]
                    //      ['quantifier' => 'distinct']
                    $queryBuilder->distinct(1 == $v || 'distinct' === strtolower($v));
                    break;
                case 'indexBy':
                    // e.g. ['indexBy' => [
                    //        ['alias' => 'u', 'indexBy' => 'u.Id']
                    //      ]]
                    if (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    foreach ($v as $indexBy) {
                        if (!is_array($indexBy) || empty($indexBy['alias'])) {
                            throw new \InvalidArgumentException(
                                __METHOD__ . " expects '{$k}' in array format and its required key 'alias'"
                            );
                        }

                        if (empty($indexBy['indexBy'])) {
                            $indexBy['indexBy'] = null;
                        }

                        try {
                            $queryBuilder->indexBy($indexBy['alias'], $indexBy['indexBy']);
                        } catch (QueryException $e) {
                            //
                        }
                    }
                    break;
                case 'reset':
                    // e.g. ['reset' => null]
                    //      ['reset' => ['select', 'from]]
                    $queryBuilder->resetDQLParts($v);
                    break;
                default:
            }
        }

        return $queryBuilder;
    }

    /**
     * Creates a UPDATE <tt>QueryBuilder</tt> object.
     *
     * <code>
     * $criteria = [
     * # 'update'     => ['from' => 'User', 'alias' => 'u'],
     *   'set'        => ['Name' => ':name', 'Email' => ':email'],
     *   'where'      => ['Id' => '?1', 'AppKey' => '?2'],
     *   'parameters' => ['name' => 'demo', 'email' => 'demo@example.com', '1' => [1], '2' => env('APP_KEY')],
     * ];
     *
     * echo $this->createUpdateQueryBuilder($criteria);
     * </code>
     *
     * @param array $criteria The criteria.
     * @param null|\Doctrine\ORM\QueryBuilder $queryBuilder Optional.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function createUpdateQueryBuilder(array $criteria, QueryBuilder $queryBuilder = null)
    {
        if (empty($criteria)) {
            throw new \InvalidArgumentException(__METHOD__ . " expects '\$criteria' must not be empty");
        }

        if (null === $queryBuilder) {
            $queryBuilder = new QueryBuilder($this->_em);
            $queryBuilder->update($this->_entityName, $this->_class->reflClass->getShortName());
        }

        $aliases = $queryBuilder->getRootAliases();

        foreach ($criteria as $k => $v) {
            switch ($k) {
                case 'update':
                    // e.g. ['update' => 'User u']
                    //      ['update' => ['from' => 'User', 'alias' => 'u']]
                    //      ['update' => ['from' => $this->userRepository, 'alias' => 'u']
                    //      ['update' => new Expr\From('User', 'u')]
                    if (is_string($v)) {
                        $parts = preg_split(PREG_PATTERN_SPACE_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                        $v = [
                            'from' => $parts[0],
                            'alias' => isset($parts[1]) ? $parts[1] : null
                        ];
                    } elseif ($v instanceof Expr\From) {
                        $v = [
                            'from' => $v->getFrom(),
                            'alias' => $v->getAlias()
                        ];
                    } elseif (!is_array($v) || empty($v['from'])) {
                        throw new \InvalidArgumentException(
                            __METHOD__ . " expects '{$k}' in array format and its required key 'from'"
                        );
                    }

                    if ($v['from'] instanceof ObjectRepository) {
                        $v['from'] = $v['from']->getClassName();
                    } elseif (false === strpos($v['from'], '\\')) {
                        $v['from'] = guessNamespace($v['from'], $this->_class->namespace);
                    }

                    if (empty($v['alias'])) {
                        $v['alias'] = shorten($v['from']);
                    }

                    $queryBuilder
                        ->resetDQLPart('from')
                        ->update($v['from'], $v['alias']);
                    $aliases = $queryBuilder->getRootAliases();
                    break;
                case 'set':
                    // e.g. ['set' => 'Id = ?1, %1$s.Name = ?2']
                    //      ['set' => ['Id' => ':id', '%1$s.Name' => ':name']]
                    //      ['set' => [
                    //        new Expr\Comparison('Id', '=', '?1'),
                    //        new Expr\Comparison('%1$s.Name', '=', '?2')
                    //      ]]
                    if (is_string($v)) {
                        $matches = preg_split(PREG_PATTERN_COMMA_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                        $v = [];

                        foreach ($matches as $m) {
                            $parts = preg_split(PREG_PATTERN_EQUALITY_SEPARATOR, $m, -1, PREG_SPLIT_NO_EMPTY);

                            if (!empty($parts[1])) {
                                $v[$parts[0]] = $parts[1];
                            }
                        }
                    } elseif (!is_array($v)) {
                        throw new \InvalidArgumentException(__METHOD__ . " expects '{$k}' in array format");
                    }

                    foreach ($v as $key => $value) {
                        if ($value instanceof Expr\Comparison) {
                            $key = $value->getLeftExpr();
                            $value = $value->getRightExpr();
                        }

                        if (false !== ($key = $this->formatName($key, $aliases))) {
                            $queryBuilder->set($key, $value);
                        }
                    }
                    break;
                case 'where':
                    // e.g. ['where' => '%1$s.Id = ?3 AND (%1$s.Name = ?4 OR %1$s.Name LIKE ?4)']
                    //      ['where' => ['Id' => ':id', '%1$s.Name' => ':name']]
                    //      ['where' => ['Id' => [1, 2], $expr->eq('u.Name', ':name')]]
                    //      ['where' => [
                    //        'Id' => [
                    //          'array' => [$obj1, $obj2], // $obj1 = (object)['Id' => 1]; $obj2 = (object)['Id' => 2];
                    //          'column_key' => 'Id'
                    //        ],
                    //        '%1$s.Name' => ':name'
                    //      ]]
                    //      ['where' => $expr->orX(
                    //        $expr->eq('u.Id', '?3'),
                    //        $expr->eq('u.Name', '?4')
                    //      )]
                    if (is_array($v)) {
                        $args = [];
                        $expr = $queryBuilder->expr();

                        foreach ($v as $key => $value) {
                            if (is_string($key)) {
                                if (false === ($key = $this->formatName($key, $aliases))) {
                                    continue;
                                }

                                if ($isArray = is_array($value)) {
                                    if (!(empty($value['array']) || empty($value['column_key']))) {
                                        $temp = [];

                                        foreach ((array) $value['array'] as $obj) {
                                            if (!is_object($obj) || !property_exists($obj, $value['column_key'])) {
                                                continue;
                                            }

                                            $temp[] = $obj->{$value['column_key']};
                                        }

                                        $value = $temp;
                                    }
                                }

                                $args[] = $isArray ? $expr->in($key, $value) : $expr->eq($key, $value);
                            } else {
                                $args[] = $value;
                            }
                        }

                        if (empty($args)) {
                            break;
                        }

                        $v = new Expr\Andx($args);
                    } elseif (is_string($v)) {
                        if (false !== ($string = @vsprintf($v, $aliases))) {
                            $v = $string;
                        }
                    }

                    $queryBuilder->where($v);
                    break;
                case 'parameters':
                    // e.g. ['parameters' => ['3' => 1, '4' => 'demo', 'id' => 1, 'name' => 'demo']]
                    //      ['parameters' => new ArrayCollection([
                    //        new Query\Parameter('3', 1),
                    //        new Query\Parameter('4', 'demo'),
                    //        new Query\Parameter('id', 1),
                    //        new Query\Parameter('name', 'demo')
                    //      ])]
                    $queryBuilder->setParameters($v);
                    break;
                default:
            }
        }

        return $queryBuilder;
    }

    /**
     * Creates a DELETE <tt>QueryBuilder</tt> object.
     *
     * <code>
     * $criteria = [
     * # 'delete'     => ['from' => 'User', 'alias' => 'u'],
     *   'where'      => ['Id' => ':id', 'AppKey' => '?1'],
     *   'parameters' => ['id' => [1], '1' => env('APP_KEY')],
     * ];
     *
     * echo $this->createDeleteQueryBuilder($criteria);
     * </code>
     *
     * @param array $criteria The criteria.
     * @param null|\Doctrine\ORM\QueryBuilder $queryBuilder Optional.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function createDeleteQueryBuilder(array $criteria, QueryBuilder $queryBuilder = null)
    {
        if (empty($criteria)) {
            throw new \InvalidArgumentException(__METHOD__ . " expects '\$criteria' must not be empty");
        }

        if (null === $queryBuilder) {
            $queryBuilder = new QueryBuilder($this->_em);
            $queryBuilder->delete($this->_entityName, $this->_class->reflClass->getShortName());
        }

        $aliases = $queryBuilder->getRootAliases();

        foreach ($criteria as $k => $v) {
            switch ($k) {
                case 'delete':
                    // e.g. ['delete' => 'User u']
                    //      ['delete' => ['from' => 'User', 'alias' => 'u']]
                    //      ['delete' => ['from' => $this->userRepository, 'alias' => 'u']
                    //      ['delete' => new Expr\From('User', 'u')]
                    if (is_string($v)) {
                        $parts = preg_split(PREG_PATTERN_SPACE_SEPARATOR, $v, -1, PREG_SPLIT_NO_EMPTY);
                        $v = [
                            'from' => $parts[0],
                            'alias' => isset($parts[1]) ? $parts[1] : null
                        ];
                    } elseif ($v instanceof Expr\From) {
                        $v = [
                            'from' => $v->getFrom(),
                            'alias' => $v->getAlias()
                        ];
                    } elseif (!is_array($v) || empty($v['from'])) {
                        throw new \InvalidArgumentException(
                            __METHOD__ . " expects '{$k}' in array format and its required key 'from'"
                        );
                    }

                    if ($v['from'] instanceof ObjectRepository) {
                        $v['from'] = $v['from']->getClassName();
                    } elseif (false === strpos($v['from'], '\\')) {
                        $v['from'] = guessNamespace($v['from'], $this->_class->namespace);
                    }

                    if (empty($v['alias'])) {
                        $v['alias'] = shorten($v['from']);
                    }

                    $queryBuilder
                        ->resetDQLPart('from')
                        ->delete($v['from'], $v['alias']);
                    $aliases = $queryBuilder->getRootAliases();
                    break;
                case 'where':
                    // e.g. ['where' => '%1$s.Id = ?3 AND (%1$s.Name = ?4 OR %1$s.Name LIKE ?4)']
                    //      ['where' => ['Id' => ':id', '%1$s.Name' => ':name']]
                    //      ['where' => ['Id' => [1, 2], $expr->eq('u.Name', ':name')]]
                    //      ['where' => [
                    //        'Id' => [
                    //          'array' => [$obj1, $obj2], // $obj1 = (object)['Id' => 1]; $obj2 = (object)['Id' => 2];
                    //          'column_key' => 'Id'
                    //        ],
                    //        '%1$s.Name' => ':name'
                    //      ]]
                    //      ['where' => $expr->orX(
                    //        $expr->eq('u.Id', '?3'),
                    //        $expr->eq('u.Name', '?4')
                    //      )]
                    if (is_array($v)) {
                        $args = [];
                        $expr = $queryBuilder->expr();

                        foreach ($v as $key => $value) {
                            if (is_string($key)) {
                                if (false === ($key = $this->formatName($key, $aliases))) {
                                    continue;
                                }

                                if ($isArray = is_array($value)) {
                                    if (!(empty($value['array']) || empty($value['column_key']))) {
                                        $temp = [];

                                        foreach ((array) $value['array'] as $obj) {
                                            if (!is_object($obj) || !property_exists($obj, $value['column_key'])) {
                                                continue;
                                            }

                                            $temp[] = $obj->{$value['column_key']};
                                        }

                                        $value = $temp;
                                    }
                                }

                                $args[] = $isArray ? $expr->in($key, $value) : $expr->eq($key, $value);
                            } else {
                                $args[] = $value;
                            }
                        }

                        if (empty($args)) {
                            break;
                        }

                        $v = new Expr\Andx($args);
                    } elseif (is_string($v)) {
                        if (false !== ($string = @vsprintf($v, $aliases))) {
                            $v = $string;
                        }
                    }

                    $queryBuilder->where($v);
                    break;
                case 'parameters':
                    // e.g. ['parameters' => ['id' => 1, '1' => 'demo']]
                    //      ['parameters' => new ArrayCollection([
                    //        new Query\Parameter('id', 1),
                    //        new Query\Parameter('1', 'demo')
                    //      ])]
                    $queryBuilder->setParameters($v);
                    break;
                default:
            }
        }

        return $queryBuilder;
    }

    // <editor-fold defaultstate="collapsed" desc="Private methods">

    /**
     * @param string $name The name.
     * @param array $aliases The aliases.
     * @param bool $checkExist Optional.
     *
     * @return bool|string
     */
    private function formatName($name, $aliases, $checkExist = true)
    {
        if (false === strpos($name, '.')) { // check for the occurrence of a dot
            if (in_array($name, $aliases, true)) {
                return $name;
            }

            if (!$checkExist || isset($this->_class->fieldMappings[$name])) {
                return $aliases[0] . '.' . $name;
            }

            return false;
        }

        return @vsprintf($name, $aliases);
    }

    /**
     * Converts the <tt>Predicate</tt> to the <tt>QueryBuilder</tt>.
     *
     * @param \Laminas\Db\Sql\Predicate\PredicateSet|PredicateInterface $predicateSet The <tt>Predicate</tt> instance.
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder The <tt>QueryBuilder</tt> instance.
     * @param array $aliases The aliases.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function resolvePredicate(PredicateInterface $predicateSet, QueryBuilder $queryBuilder, $aliases)
    {
        foreach ($predicateSet->getPredicates() as $value) {
            $predicate = $value[1];

            if (method_exists($predicate, 'getIdentifier')) {
                /* @var mixed|\Laminas\Db\Sql\Predicate\Operator|\Laminas\Db\Sql\Predicate\Between $predicate */
                if (false !== ($identifier = $this->formatName($predicate->getIdentifier(), $aliases))) {
                    $predicate->setIdentifier($identifier);
                }
            }

            $type = (new \ReflectionObject($predicate))
                ->getShortName();

            switch ($type) {
                case 'Predicate': // nest/unnest
                    $expr = $this
                        ->resolvePredicate($predicate, $this->createQueryBuilder($aliases[0]), $aliases)
                        ->getDQLPart('where');
                    break;
                case 'Between':
                case 'NotBetween':
                    $expr = sprintf(
                        $predicate->getSpecification(),
                        $predicate->getIdentifier(),
                        $predicate->getMinValue(),
                        $predicate->getMaxValue()
                    );
                    break;
                case 'Expression':
                    /* @var \Laminas\Db\Sql\Predicate\Expression $predicate */
                    $expr = $predicate->getExpression();
                    $queryBuilder->setParameters($predicate->getParameters());

                    if (false !== ($string = @vsprintf($expr, $aliases))) {
                        $expr = $string;
                    }
                    break;
                case 'In':
                case 'NotIn':
                    /* @see \Doctrine\ORM\Query\Expr::in */
                    /* @see \Doctrine\ORM\Query\Expr::notIn */
                    /* @var \Laminas\Db\Sql\Predicate\In $predicate */
                    $expr = $queryBuilder->expr()
                        ->{lcfirst($type)}($predicate->getIdentifier(), $predicate->getValueSet());
                    break;
                case 'IsNotNull':
                case 'IsNull':
                    /* @see \Doctrine\ORM\Query\Expr::isNull */
                    /* @see \Doctrine\ORM\Query\Expr::isNotNull */
                    /* @var \Laminas\Db\Sql\Predicate\IsNull $predicate */
                    $expr = $queryBuilder->expr()
                        ->{lcfirst($type)}($predicate->getIdentifier());
                    break;
                case 'Like':
                case 'NotLike':
                    /* @see \Doctrine\ORM\Query\Expr::like */
                    /* @see \Doctrine\ORM\Query\Expr::notLike */
                    /* @var \Laminas\Db\Sql\Predicate\Like $predicate */
                    $expr = $queryBuilder->expr()
                        ->{lcfirst($type)}($predicate->getIdentifier(), $predicate->getLike());
                    break;
                case 'Literal':
                    /* @var \Laminas\Db\Sql\Predicate\Literal $predicate */
                    $expr = $queryBuilder->expr()
                        ->literal($predicate->getLiteral());
                    $expr = trim($expr->getParts()[0], "'");

                    if (false !== ($string = @vsprintf($expr, $aliases))) {
                        $expr = $string;
                    }
                    break;
                default:
                    if (PredicateInterface::TYPE_IDENTIFIER === $predicate->getLeftType()) {
                        $left = $predicate->getLeft();
                        $right = $predicate->getRight();
                    } else {
                        $left = $predicate->getRight();
                        $right = $predicate->getLeft();
                    }

                    if (false === ($left = $this->formatName($left, $aliases))) {
                        continue 2;
                    }

                    $expr = new Expr\Comparison($left, $predicate->getOperator(), $right);
            }

            /* @see \Doctrine\ORM\QueryBuilder::andWhere */
            /* @see \Doctrine\ORM\QueryBuilder::orWhere */
            $queryBuilder->{strtolower($value[0]) . 'Where'}($expr);
        }

        return $queryBuilder;
    }

    // </editor-fold>
}
