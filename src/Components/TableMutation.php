<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use ServiceManager;

class TableMutation extends TableQuery
{
    public function args()
    {
        $pkArgs = [
            'ids'      => ['name' => 'ids', 'type' => Type::listOf(Type::int())],
            'id_field' => ['name' => 'id_field', 'type' => Type::string()],
            'id_type'  => ['name' => 'id_type', 'type' => Type::string()],
            'filter'   => ['name' => 'filter', 'type' => Type::string()],
            'limit'    => ['name' => 'limit', 'type' => Type::int()],
            'offset'   => ['name' => 'offset', 'type' => Type::int()],
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
        switch ($this->verb) {
            case Verbs::POST:
            case Verbs::PUT:
            case Verbs::PATCH:
                $request->setContent(['resource' => [$args]]);
                break;
            case Verbs::DELETE:
                break;
        }
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