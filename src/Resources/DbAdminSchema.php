<?php

namespace DreamFactory\Core\Database\Resources;

use DreamFactory\Core\Components\DataValidator;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\GraphQL\Query\BaseQuery;
use DreamFactory\Core\GraphQL\Type\BaseType;
use DreamFactory\Core\Models\Service;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL;

class DbAdminSchema extends BaseDbResource
{
    use DataValidator;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with database schema
     */
    const RESOURCE_NAME = 'schema';
    /**
     * Replacement tag for dealing with schema schema events
     */
    const EVENT_IDENTIFIER = '{schema_name}';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var mixed Resource ID.
     */
    protected $resourceId2;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@inheritdoc}
     */
    public function getResourceName()
    {
        return static::RESOURCE_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function listResources($schema = null, $refresh = false)
    {
        $result = $this->parent->getSchemas($refresh);
        $resources = [];
        foreach ($result as $schema) {
            if (!empty($this->getPermissions($schema->name))) {
                $resources[] = $schema->name;
            }
        }

        return $resources;
    }

    /**
     * @param null $schema
     * @param bool $refresh
     *
     * @return array
     */
    public function listAccessComponents($schema = null, $refresh = false)
    {
        $output = [];
        $result = $this->listResources($schema, $refresh);
        foreach ($result as $name) {
            $output[] = $this->getResourceName() . '/' . $name . '/';
            $output[] = $this->getResourceName() . '/' . $name . '/*';
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function getResources()
    {
        $refresh = $this->request->getParameterAsBool(ApiOptions::REFRESH);
        $result = $this->parent->parent->getSchemas($refresh);
        $resources = [];
        foreach ($result as $schema) {
            $access = $this->getPermissions($schema->name);
            if (!empty($access)) {
                $info = $schema->toArray();
                $info['access'] = VerbsMask::maskToArray($access);
                $resources[] = $info;
            }
        }

        return $resources;
    }

    /**
     * @inheritdoc
     */
    protected function setResourceMembers($resourcePath = null)
    {
        parent::setResourceMembers($resourcePath);
        if (!empty($this->resourceArray)) {
            if (null !== ($id = array_get($this->resourceArray, 2))) {
                $this->resourceId2 = $id;
            }
        }

        return $this;
    }

    /**
     * Refreshes all schema associated with this db connection:
     */
    public function refreshCachedSchemas()
    {
        $this->parent->flush();
        event(new ServiceModifiedEvent(
            new Service([
                'id'   => $this->getServiceId(),
                'name' => $this->getServiceName()
            ])
        ));
    }

    /**
     * @param string $schema
     * @param string $action
     *
     * @throws BadRequestException
     * @throws \DreamFactory\Core\Exceptions\ForbiddenException
     */
    protected function validateSchemaAccess($schema, $action = null)
    {
        if (empty($schema)) {
            throw new BadRequestException('Schema name can not be empty.');
        }

        $this->doesSchemaExist($schema);
        $this->checkPermission($action, $schema);
    }

    /**
     * @inheritdoc
     */
    protected function getEventName()
    {
        $suffix = '';
        switch (count($this->resourceArray)) {
            case 1:
                $suffix = '.' . static::EVENT_IDENTIFIER;
                break;
            case 2:
                $suffix = '.' . static::EVENT_IDENTIFIER . '.{field}';
                break;
            default:
                break;
        }

        return parent::getEventName() . $suffix;
    }


    /**
     * @inheritdoc
     */
    protected function firePreProcessEvent($name = null, $resource = null)
    {
        // fire default first
        // Try the generic schema event
        parent::firePreProcessEvent($name, $resource);

        // also fire more specific event
        // Try the actual schema name event
        switch (count($this->resourceArray)) {
            case 1:
                parent::firePreProcessEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $resource);
                break;
            case 2:
                parent::firePreProcessEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $this->resourceArray[1]);
                break;
        }
    }

    /**
     * @inheritdoc
     */
    protected function firePostProcessEvent($name = null, $resource = null)
    {
        // fire default first
        // Try the generic schema event
        parent::firePostProcessEvent($name, $resource);

        // also fire more specific event
        // Try the actual schema name event
        switch (count($this->resourceArray)) {
            case 1:
                parent::firePostProcessEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $resource);
                break;
            case 2:
                parent::firePostProcessEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $this->resourceArray[1]);
                break;
        }
    }

    /**
     * @inheritdoc
     */
    protected function fireFinalEvent($name = null, $resource = null)
    {
        // fire default first
        // Try the generic schema event
        parent::fireFinalEvent($name, $resource);

        // also fire more specific event
        // Try the actual schema name event
        switch (count($this->resourceArray)) {
            case 1:
                parent::fireFinalEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $resource);
                break;
            case 2:
                parent::fireFinalEvent(str_replace(static::EVENT_IDENTIFIER, $this->resourceArray[0],
                    $this->getEventName()), $this->resourceArray[1]);
                break;
        }
    }

    public function getGraphQLSchema()
    {
        $tName = 'db_schema_schema';
        $types = [
            'db_schema_schema_relation' => new BaseType(RelationSchema::getSchema()),
            'db_schema_schema_field'    => new BaseType(ColumnSchema::getSchema()),
            'db_schema'          => new BaseType(SchemaSchema::getSchema()),
        ];
        GraphQL::addTypes($types);

        $queries = [];
        $qName = $this->formOperationName(Verbs::GET);
        $queries[$qName] = new BaseQuery([
            'name'    => $qName,
            'type'    => Type::string(),
            'args'    => [
                'name'    => ['name' => 'name', 'type' => Type::nonNull(Type::string())],
                'refresh' => ['name' => 'refresh', 'type' => Type::boolean()],
            ],
            'resolve' => function ($root, $args, $context, ResolveInfo $info) {
                    return $args['name'];
            },
        ]);
        $qName = $this->formOperationName(Verbs::GET, null, true);
        $queries[$qName] = new BaseQuery([
            'name'    => $qName,
            'type'    => '['.Type::STRING.']',
            'args'    => [
                'refresh' => ['name' => 'refresh', 'type' => Type::boolean()],
            ],
            'resolve' => function ($root, $args, $context, ResolveInfo $info) {
                    return $this->parent->getSchemas(array_get_bool($args, 'refresh'));
            },
        ]);
        $qName = $qName . 'Names';
        $queries[$qName] = new BaseQuery([
            'name'    => $qName,
            'type'    => '['.Type::STRING.']',
            'args'    => [
                'refresh' => ['name' => 'refresh', 'type' => Type::boolean()],
            ],
            'resolve' => function ($root, $args, $context, ResolveInfo $info) {
                return $this->parent->getSchemas(array_get_bool($args, 'refresh'));
            },
        ]);
        $mutations = [];

        return ['query' => $queries, 'mutation' => $mutations];
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $pluralClass = str_plural($class);
        $resourceName = strtolower($this->name);
        $path = '/' . $resourceName;

        $paths = [
            $path                                                => [
                'get'   => [
                    'summary'     => 'Retrieve one or more ' . $pluralClass . '.',
                    'description' =>
                        'Use the \'ids\' parameter to limit records that are returned. ' .
                        'By default, all records up to the maximum are returned. ' .
                        'Use the \'fields\' parameters to limit properties returned for each record. ' .
                        'By default, all fields are returned for each record.',
                    'operationId' => 'get' . $capitalized . $pluralClass,
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::FIELDS),
                        ApiOptions::documentOption(ApiOptions::IDS),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SchemaSchemas']
                    ],
                ],
            ],
            $path . '/{schema_name}'                              => [
                'parameters' => [
                    [
                        'name'        => 'schema_name',
                        'description' => 'Name of the schema to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve schema definition for the given schema.',
                    'description' => 'This describes the schema, its fields and relations to other schemas.',
                    'operationId' => 'describe' . $capitalized . 'Schema',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SchemaSchema']
                    ],
                ],
            ],
        ];

        return $paths;
    }
}