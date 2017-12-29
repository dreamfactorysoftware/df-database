<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Components\Service2ServiceRequest;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\GraphQL\Type\BaseType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use ServiceManager;

class TableRecordMutation extends TableRecordQuery
{
    public function args()
    {
        $args = parent::args(); // Get primary keys

        switch ($this->verb) {
            case Verbs::POST:
            case Verbs::PUT:
                // need to allow setting all fields and relations
                foreach ($this->tableSchema->getColumns() as $name => $info) {
                    if ($type = BaseType::convertType($info->type)) {
                        if ($info->getRequired()) {
                            $type = Type::nonNull($type);
                        }
                        $args[$name] = ['name' => $name, 'type' => $type];
                    }
                }
                break;
            case Verbs::PATCH:
                // need to allow setting all fields and relations
                foreach ($this->tableSchema->getColumns() as $name => $info) {
                    if ($type = BaseType::convertType($info->type)) {
                        $args[$name] = ['name' => $name, 'type' => $type];
                    }
                }
                break;
            case Verbs::DELETE:
                // just uses identifiers
                break;
        }

        return $args;
    }

    /**
     * @param             $root
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     * @return array
     * @throws BadRequestException
     * @throws RestException
     * @throws \Exception
     */
    public function resolve($root, $args, $context, ResolveInfo $info)
    {
        $table = $this->tableSchema->getName(true);
        $selection = $this->getFieldSelection($info);
        if (empty($id = $this->getIdentifier($args))) {
            throw new BadRequestException("Identifying value(s) are required to use this interface.");
        }
        $request = new Service2ServiceRequest($this->verb, $selection);
        switch ($this->verb) {
            case Verbs::POST:
            case Verbs::PUT:
            case Verbs::PATCH:
                $request->setContent($args);
                break;
            case Verbs::DELETE:
                break;
        }
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
}