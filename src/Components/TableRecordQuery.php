<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\GraphQL\Query\BaseQuery;
use DreamFactory\Core\GraphQL\Type\BaseType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use ServiceManager;

class TableRecordQuery extends BaseQuery
{
    /** @var string */
    protected $service;
    /** @var TableSchema */
    protected $tableSchema;
    /** @var string */
    protected $verb;

    public function __construct($attributes = [])
    {
        $this->service = array_get($attributes, 'service');
        $this->tableSchema = array_get($attributes, 'schema');
        $this->verb = array_get($attributes, 'verb', Verbs::GET);

        parent::__construct(array_except($attributes, ['service', 'schema','verb']));
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
     * @throws RestException
     * @throws \Exception
     */
    public function resolve($root, $args, $context, ResolveInfo $info)
    {
        $table = $this->tableSchema->getName(true);
        $selection = $this->getFieldSelection($info);
        $id = $this->getIdentifier($args);
        $params = array_merge($args, $selection);
        $request = new Service2ServiceRequest($this->verb, $params);
        $response = ServiceManager::handleServiceRequest($request, $this->service, '_table/' . $table . '/' . $id);
        $status = $response->getStatusCode();
        $content = $response->getContent();
        if ($status >= 300) {
            if (isset($content, $content['error'])) {
                $error = $content['error'];
                extract($error);
                /** @noinspection PhpUndefinedVariableInspection */
                throw new RestException($status, $message, $code);
            }

            throw new RestException($status, 'GraphQL query failed but returned invalid format.');
        }
        $response = $content;

        return $response;
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