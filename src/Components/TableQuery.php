<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

class TableQuery extends TableRecordQuery
{
    public function type()
    {
        return Type::listOf(parent::type());
    }

    public function args()
    {
        $pkArgs = [
            'ids'      => ['name' => 'ids', 'type' => Type::listOf(Type::int())],
            'id_field' => ['name' => 'id_field', 'type' => Type::string()],
            'id_type'  => ['name' => 'id_type', 'type' => Type::string()],
            'filter'   => ['name' => 'filter', 'type' => Type::string()],
            'limit'    => ['name' => 'limit', 'type' => Type::int()],
            'offset'   => ['name' => 'offset', 'type' => Type::int()],
            'order'    => ['name' => 'order', 'type' => Type::string()],
            'group'    => ['name' => 'group', 'type' => Type::string()],
        ];

        return $pkArgs;
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
        $table = $this->tableSchema->getName(true);
        $this->resource = '_table/' . $table;
        $result = parent::resolve($root, $args, $context, $info);
        $response = ResourcesWrapper::unwrapResources($result);

        return $response;
    }
}