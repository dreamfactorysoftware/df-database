<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\GraphQL\Query\ServiceSingleResourceQuery;
use DreamFactory\Core\GraphQL\Type\BaseType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL;

class TableRecordQuery extends ServiceSingleResourceQuery
{
    /** @var TableSchema */
    protected $tableSchema;

    public function __construct($attributes = [])
    {
        $this->tableSchema = array_get($attributes, 'schema');

        parent::__construct(array_except($attributes, ['schema']));
    }

    public function type()
    {
        return GraphQL::type($this->makeTypeName());
    }

    public function args()
    {
        $pkArgs = [];
        foreach ($this->tableSchema->getPrimaryKeyColumns() as $pk => $info) {
            $pkArgs[$pk] = ['name' => $pk, 'type' => Type::nonNull(BaseType::convertType($info->type))];
        }

        return $pkArgs;
    }

    /**
     * @param             $root
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     * @return array
     * @throws \DreamFactory\Core\Exceptions\RestException
     * @throws \Exception
     */
    public function resolve($root, $args, $context, ResolveInfo $info)
    {
        $table = $this->tableSchema->getName(true);
        $id = $this->getIdentifier($args);
        $this->resource = '_table/' . $table . '/' . $id;

        return parent::resolve($root, $args, $context, $info);
    }

    protected function makeTypeName($as_input = false)
    {
        $name = $this->tableSchema->getName(true);
        $name = $this->service . '_table_' . $name;
        if ($as_input) {
            $name .= '_input';
        }

        return $name;
    }

    protected function getFieldSelection(ResolveInfo $info)
    {
        $fields = [];
        $related = [];
        $selection = $info->getFieldSelection($depth = 1);
        foreach ($selection as $key => $value) {
            if (is_array($value)) {
                $related[$key] = ['fields' => array_keys($value)];
            } else {
                $fields[] = $key;
            }
        }

        return ['fields' => $fields, 'related' => $related];
    }

    protected function getIdentifier(&$args)
    {
        $id = '';
        foreach ($this->tableSchema->getPrimaryKey() as $pk) {
            if (empty($id)) {
                $id = array_get($args, $pk);
            } else {
                $id = '(' . $id . ',' . array_get($args, $pk) . ')';
            }
            unset($args[$pk]);
        }

        return $id;
    }
}