<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL;
use ServiceManager;

class TableQuery extends TableRecordQuery
{
    public function type()
    {
        if ($this->type instanceof GraphQL\Type\Definition\ListOfType) {
            return $this->type;
        }
        if (($this->type instanceof GraphQL\Type\Definition\ObjectType) ||
            ($this->type instanceof GraphQL\Type\Definition\ScalarType)) {
            return Type::listOf($this->type);
        }

        return Type::listOf(GraphQL::type($this->type));
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
        $selection = $this->getFieldSelection($info);
        $params = array_merge($args, $selection);
        $request = new Service2ServiceRequest($this->verb, $params);
        $response = ServiceManager::handleServiceRequest($request, $this->service, '_table/' . $table);
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
        $response = ResourcesWrapper::unwrapResources($content);

        return $response;
    }
}