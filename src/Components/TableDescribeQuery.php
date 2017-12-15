<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\GraphQL\Query\ServiceMultiResourceQuery;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL;

class TableDescribeQuery extends ServiceMultiResourceQuery
{
    public function type()
    {
        if ($this->type instanceof GraphQL\Type\Definition\Type) {
            return $this->type;
        }

        return GraphQL::type($this->type);
    }

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