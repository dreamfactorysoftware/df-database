<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\GraphQL\Query\ServiceMultiResourceQuery;
use GraphQL\Type\Definition\ResolveInfo;

class TableDescribeQuery extends ServiceMultiResourceQuery
{
    /**
     * @param             $root
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     * @return array
     * @throws RestException
     * @throws \Exception
     */
    public function resolve($root, $args, $context, ResolveInfo $info)
    {
        $args['ids'] = [array_get($args, 'name')];
        unset($args['name']);
        $response = parent::resolve($root, $args, $context, $info);

        return current($response);
    }
}