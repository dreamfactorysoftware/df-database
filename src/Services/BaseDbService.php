<?php

namespace DreamFactory\Core\Database\Services;

use DreamFactory\Core\Contracts\DbExtrasInterface;
use DreamFactory\Core\Contracts\DbSchemaInterface;
use DreamFactory\Core\Database\Components\DbSchemaExtras;
use DreamFactory\Core\Database\Enums\DbFunctionUses;
use DreamFactory\Core\Database\Enums\FunctionTypes;
use DreamFactory\Core\Database\Resources\BaseDbResource;
use DreamFactory\Core\Database\Resources\DbSchemaResource;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\DbResourceTypes;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotImplementedException;
use DreamFactory\Core\GraphQL\Contracts\GraphQLHandlerInterface;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Illuminate\Database\ConnectionInterface;
use ServiceManager;
use Config;

abstract class BaseDbService extends BaseRestService implements DbExtrasInterface, GraphQLHandlerInterface
{
    use DbSchemaExtras;

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @type bool
     */
    protected $allowUpsert = false;
    /**
     * @type int
     */
    protected $maxRecords = 0;
    /**
     * @type bool
     */
    protected $cacheEnabled = false;
    /**
     * @var ConnectionInterface
     */
    protected $dbConn;
    /**
     * @var DbSchemaInterface
     */
    protected $schema;
    /**
     * @var string
     */
    protected $userSchema;
    /**
     * @var string
     */
    protected $defaultSchema;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new Database Service
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $this->allowUpsert = array_get_bool($this->config, 'allow_upsert');
        $this->maxRecords = intval(array_get($this->config, 'max_records', 0));
        $this->cacheEnabled = array_get_bool($this->config, 'cache_enabled');
        $this->cacheTTL = intval(array_get($this->config, 'cache_ttl', \Config::get('df.default_cache_ttl')));
        $this->userSchema = array_get($this->config, 'schema');
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->dbConn = null;
        } catch (\Exception $ex) {
            error_log("Failed to disconnect from database.\n{$ex->getMessage()}");
        }
    }

    public function getMaxRecordsLimit($default = 1000)
    {
        $envCap = intval(Config::get('database.max_records_returned', 100000));
        $maxRecords = ($this->maxRecords > 0) ? $this->maxRecords : $default;

        return ($maxRecords > $envCap) ? $envCap : $maxRecords;
    }

    public function upsertAllowed()
    {
        return $this->allowUpsert;
    }

    public function getCacheEnabled()
    {
        return $this->cacheEnabled;
    }

    public function getResourceHandlers()
    {
        return [
            DbSchemaResource::RESOURCE_NAME => [
                'name'       => DbSchemaResource::RESOURCE_NAME,
                'class_name' => DbSchemaResource::class,
                'label'      => 'Schema Table',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $output = parent::getAccessList();
        $refresh = ($this->request ? $this->request->getParameterAsBool(ApiOptions::REFRESH) : false);
        $schema = ($this->request ? $this->request->getParameter(ApiOptions::SCHEMA, '') : false);

        foreach ($this->getResourceHandlers() as $resourceInfo) {
            $className = $resourceInfo['class_name'];

            if (!class_exists($className)) {
                throw new InternalServerErrorException('Service configuration class name lookup failed for resource ' .
                    $this->resourcePath);
            }

            /** @var BaseDbResource $resource */
            $resource = $this->instantiateResource($className, $resourceInfo);
            $access = $this->getPermissions($resource->name);
            if (!empty($access)) {
                try {
                    $results = $resource->listAccessComponents($schema, $refresh);
                    $output[] = $resource->name . '/';
                    $output[] = $resource->name . '/*';
                    $output = array_merge($output, $results);
                } catch (NotImplementedException $ex) {
                    // carry on
                }
            }
        }

        return $output;
    }

    /**
     * @param bool $refresh
     * @return array
     * @throws InternalServerErrorException
     */
    public function getGraphQLSchema($refresh = false)
    {
//        $cacheKey = 'graphql_schema';
//        if ($refresh) {
//            $this->removeFromCache($cacheKey);
//        }

//        return $this->rememberCacheForever($cacheKey, function () use ($refresh) {
        $base = ['query' => [], 'mutation' => [], 'types' => []];
        foreach ($this->getResourceHandlers() as $resourceInfo) {
            $className = array_get($resourceInfo, 'class_name');
            if (!class_exists($className)) {
                throw new InternalServerErrorException('Service configuration class name lookup failed for resource ' .
                    $className);
            }

            /** @var BaseRestResource $resource */
            $resource = $this->instantiateResource($className, $resourceInfo);
            if ($resource instanceof GraphQLHandlerInterface) {
                $content = $resource->getGraphQLSchema($refresh);
                if (isset($content['query'])) {
                    $base['query'] = array_merge((array)array_get($base, 'query'), (array)$content['query']);
                }
                if (isset($content['mutation'])) {
                    $base['mutation'] = array_merge((array)array_get($base, 'mutation'), (array)$content['mutation']);
                }
                if (isset($content['types'])) {
                    $base['types'] = array_merge((array)array_get($base, 'types'), (array)$content['types']);
                }
            }
        }

        return $base;
//        });
    }

    /**
     * @throws InternalServerErrorException
     */
    protected function initializeConnection()
    {
        throw new InternalServerErrorException('Database connection has not been initialized.');
    }

    /**
     * @throws \Exception
     */
    public function getConnection()
    {
        if (!isset($this->dbConn)) {
            $this->initializeConnection();
        }

        return $this->dbConn;
    }

    /**
     * @throws \Exception
     * @return DbSchemaInterface
     */
    public function getSchema()
    {
        if (!isset($this->schema)) {
            $this->initializeConnection();
            if (!isset($this->schema)) {
                throw new InternalServerErrorException('Database schema extension has not been initialized.');
            }
        }

        return $this->schema;
    }

    /**
     * @return mixed|null|string
     * @throws \Exception
     */
    public function getDefaultSchema()
    {
        if (!$this->defaultSchema) {
            if (is_null($this->defaultSchema = $this->getFromCache('default_schema'))) {
                $this->defaultSchema = $this->getSchema()->getDefaultSchema();
                $this->addToCache('default_schema', $this->defaultSchema, true);
            }
        }

        return $this->defaultSchema;
    }

    /**
     * @return mixed|null|string
     * @throws \Exception
     */
    public function getNamingSchema()
    {
        switch (strtolower($this->userSchema)) {
            case null:
            case '':
            case 'all':
            case 'default':
                return $this->getDefaultSchema();
            default:
                return $this->userSchema;
        }
    }

    /**
     * @param bool $refresh
     * @return array|mixed
     * @throws \Exception
     */
    public function getSchemas($refresh = false)
    {
        if ($refresh || (is_null($result = $this->getFromCache('schemas')))) {
            if (!empty($this->userSchema) && (0 !== strcasecmp($this->userSchema, 'all'))) {
                if (0 === strcasecmp($this->userSchema, 'default')) {
                    return [$this->getDefaultSchema()];
                }

                return [$this->userSchema];
            }

            $result = $this->getSchema()->getResourceNames(DbResourceTypes::TYPE_SCHEMA);
            $defaultSchema = $this->getDefaultSchema();
            if (!empty($defaultSchema) && (false === array_search($defaultSchema, $result))) {
                $result[] = $defaultSchema;
            }

            natcasesort($result);
            $this->addToCache('schemas', $result, true);
        }

        return $result;
    }

    /**
     * @param string $schema
     * @param bool   $refresh
     * @return TableSchema[]
     * @throws \Exception
     */
    public function getTableNames($schema = null, $refresh = false)
    {
        if ($refresh || (is_null($tables = $this->getFromCache('tables')))) {
            $tables = [];
            $defaultSchema = $this->getNamingSchema();
            $schemaIF = $this->getSchema();
            foreach ($this->getSchemas($refresh) as $schemaName) {
                $addSchema = (!empty($schemaName) && ($defaultSchema !== $schemaName));

                $result = $schemaIF->getResourceNames(DbResourceTypes::TYPE_TABLE, $schemaName);

                // Until views are separated as separate resource
                if ($schemaIF->supportsResourceType(DbResourceTypes::TYPE_VIEW)) {
                    $views = $schemaIF->getResourceNames(DbResourceTypes::TYPE_VIEW, $schemaName);
                    $result = array_merge($result, $views);
                }

                foreach ($result as &$table) {
                    if ($addSchema) {
                        $table->name = ($addSchema) ? $table->internalName : $table->resourceName;
                    }
                    $tables[strtolower($table->name)] = $table;
                }
            }
            ksort($tables, SORT_NATURAL); // sort alphabetically

            // merge db extras
            if (!empty($extrasEntries = $this->getSchemaExtrasForTables(array_keys($tables)))) {
                foreach ($extrasEntries as $extras) {
                    if (!empty($extraName = strtolower(strval($extras['table'])))) {
                        if (array_key_exists($extraName, $tables)) {
                            $tables[$extraName]->fill(array_except($extras, 'id'));
                        }
                    }
                }
            }

            $this->addToCache('tables', $tables, true);
        }
        if (!empty($schema)) {
            $out = [];
            foreach ($tables as $table => $info) {
                if (starts_with($table, $schema . '.')) {
                    $out[$table] = $info;
                }
            }

            $tables = $out;
        }

        return $tables;
    }

    /**
     * @param      $name
     * @param bool $refresh
     * @return TableSchema|mixed|null
     * @throws \Exception
     */
    public function getTableSchema($name, $refresh = false)
    {
        $result = null;
        $cacheKey = 'table:' . strtolower($name);
        if ($refresh || (is_null($result = $this->getFromCache($cacheKey)))) {
            $schema = $this->getSchema();
            if ($tableSchema = array_get($this->getTableNames(), strtolower($name))) {
                /** @type TableSchema $result */
                if (!$result = $schema->getResource(DbResourceTypes::TYPE_TABLE, $tableSchema)) {
                    if ($schema->supportsResourceType(DbResourceTypes::TYPE_VIEW)) {
                        $result = $schema->getResource(DbResourceTypes::TYPE_VIEW, $tableSchema);
                    }
                }
                if ($result) {
                    $tableSchema = $result;

                    // merge db relationships
                    if (!empty($references = $this->getTableConstraints($refresh))) {
                        $this->updateTableWithConstraints($tableSchema, $references);
                    }

                    // merge db extras
                    if (!empty($extras = $this->getSchemaExtrasForFields($tableSchema->name))) {
                        foreach ($extras as $extra) {
                            if (!empty($columnName = array_get($extra, 'field'))) {
                                unset($extra['field']);
                                if (!empty($type = array_get($extra, 'extra_type'))) {
                                    $extra['type'] = $type;
                                    // upgrade old entries
                                    if ('virtual' === $type) {
                                        $extra['is_virtual'] = true;
                                        if (!empty($functionInfo = array_get($extra, 'db_function'))) {
                                            $type = $extra['type'] = array_get($functionInfo, 'type',
                                                DbSimpleTypes::TYPE_STRING);
                                            if ($function = array_get($functionInfo, 'function')) {
                                                $extra['db_function'] = [
                                                    [
                                                        'use'           => [DbFunctionUses::SELECT],
                                                        'function'      => $function,
                                                        'function_type' => FunctionTypes::DATABASE,
                                                    ]
                                                ];
                                            }
                                            if ($aggregate = array_get($functionInfo, 'aggregate')) {
                                                $extra['is_aggregate'] = $aggregate;
                                            }
                                        }
                                    }
                                }
                                unset($extra['extra_type']);

                                if (!empty($alias = array_get($extra, 'alias'))) {
                                    $extra['quotedAlias'] = $schema->quoteColumnName($alias);
                                }

                                if (null !== $c = $result->getColumn($columnName)) {
                                    $c->fill($extra);
                                } elseif (!empty($type) && (array_get($extra, 'is_virtual') ||
                                        !$schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD))) {
                                    $extra['name'] = $columnName;
                                    $c = new ColumnSchema($extra);
                                    $c->quotedName = $schema->quoteColumnName($c->name);
                                    $tableSchema->addColumn($c);
                                }
                            }
                        }
                    }
                    if (!empty($extras = $this->getSchemaVirtualRelationships($tableSchema->name))) {
                        foreach ($extras as $extra) {
                            $si = array_get($extra, 'ref_service_id');
                            if ($this->getServiceId() !== $si) {
                                $extra['ref_service'] = ServiceManager::getServiceNameById($si);
                            }
                            $si = array_get($extra, 'junction_service_id');
                            if (!empty($si) && ($this->getServiceId() !== $si)) {
                                $extra['junction_service'] = ServiceManager::getServiceNameById($si);
                            }
                            $extra['is_virtual'] = true;
                            $relation = new RelationSchema($extra);
                            $tableSchema->addRelation($relation);
                        }
                    }
                    if (!empty($extras = $this->getSchemaExtrasForRelated($tableSchema->name))) {
                        foreach ($extras as $extra) {
                            if (!empty($relatedName = array_get($extra, 'relationship'))) {
                                if (null !== $relationship = $tableSchema->getRelation($relatedName)) {
                                    $relationship->fill($extra);
                                    if (isset($extra['always_fetch']) && $extra['always_fetch']) {
                                        $tableSchema->fetchRequiresRelations = true;
                                    }
                                }
                            }
                        }
                    }
                    $tableSchema->discoveryCompleted = true;
                    $this->addToCache($cacheKey, $tableSchema, true);
                }
            }
        }

        return $result;
    }

    /**
     * @param bool $refresh
     * @return array|mixed|null
     * @throws \Exception
     */
    protected function getTableConstraints($refresh = false)
    {
        $result = null;
        $cacheKey = 'table_constraints';
        if ($refresh || (is_null($result = $this->getFromCache($cacheKey)))) {
            $schema = $this->getSchema();
            if ($schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_CONSTRAINT)) {
                $schemas = $this->getSchemas($refresh);
                $result = $schema->getResourceNames(DbResourceTypes::TYPE_TABLE_CONSTRAINT, $schemas);
                $this->addToCache($cacheKey, $result, true);
            }
        }

        return $result;
    }

    /**
     * @param TableSchema $table
     * @param             $constraints
     * @throws \Exception
     */
    protected function updateTableWithConstraints(TableSchema $table, $constraints)
    {
        $serviceId = $this->getServiceId();
        $defaultSchema = $this->getNamingSchema();

        // handle local constraints
        $ts = strtolower($table->schemaName);
        $tn = strtolower($table->resourceName);
        if (isset($constraints[$ts][$tn])) {
            foreach ($constraints[$ts][$tn] as $conName => $constraint) {
                $table->constraints[strtolower($conName)] = $constraint;
                $cn = (array)$constraint['column_name'];
                $type = strtolower(array_get($constraint, 'constraint_type', ''));
                switch ($type[0]) {
                    case 'p':
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isPrimaryKey = true;
                                if ((1 === count($cn)) && $column->autoIncrement &&
                                    (DbSimpleTypes::TYPE_INTEGER === $column->type)) {
                                    $column->type = DbSimpleTypes::TYPE_ID;
                                }
                                $table->addColumn($column);
                                $table->addPrimaryKey($colName);
                            }
                        }
                        break;
                    case 'u':
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isUnique = true;
                                $table->addColumn($column);
                            }
                        }
                        break;
                    case 'f':
                        // belongs_to
                        $rts = array_get($constraint, 'referenced_table_schema', '');
                        $rtn = array_get($constraint, 'referenced_table_name', '');
                        $rcn = (array)array_get($constraint, 'referenced_column_name');
                        $name = ($rts == $defaultSchema) ? $rtn : $rts . '.' . $rtn;
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isForeignKey = true;
                                $column->refTable = $name;
                                $column->refField = array_get($rcn, $cndx);
                                if ((1 === count($rcn)) && (DbSimpleTypes::TYPE_INTEGER === $column->type)) {
                                    $column->type = DbSimpleTypes::TYPE_REF;
                                }
                                $table->addColumn($column);
                            }
                        }

                        // Add it to our foreign references as well
                        $relation = new RelationSchema([
                            'type'           => RelationSchema::BELONGS_TO,
                            'field'          => $cn,
                            'ref_service_id' => $serviceId,
                            'ref_table'      => $name,
                            'ref_field'      => $rcn,
                            'ref_on_update'  => array_get($constraint, 'update_rule'),
                            'ref_on_delete'  => array_get($constraint, 'delete_rule'),
                        ]);

                        $table->addRelation($relation);
                        break;
                }
            }
        }

        foreach ($constraints as $schemaName => $schemas) {
            foreach ($schemas as $tableName => $tables) {
                foreach ($tables as $constraintName => $constraint) {
                    if (0 !== strncasecmp('f', strtolower(array_get($constraint, 'constraint_type', '')), 1)) {
                        continue;
                    }

                    $rts = array_get($constraint, 'referenced_table_schema', '');
                    $rtn = array_get($constraint, 'referenced_table_name');
                    if ((0 === strcasecmp($rtn, $table->resourceName)) && (0 === strcasecmp($rts, $table->schemaName))) {
                        $ts = array_get($constraint, 'table_schema', '');
                        $tn = array_get($constraint, 'table_name');
                        $tsk = strtolower($ts);
                        $tnk = strtolower($tn);
                        $cn = array_get($constraint, 'column_name');
                        $rcn = array_get($constraint, 'referenced_column_name');
                        $name = ($ts == $defaultSchema) ? $tn : $ts . '.' . $tn;
                        $type = RelationSchema::HAS_MANY;
                        if (isset($constraints[$tsk][$tnk])) {
                            foreach ($constraints[$tsk][$tnk] as $constraintName2 => $constraint2) {
                                $type2 = strtolower(array_get($constraint2, 'constraint_type', ''));
                                switch ($type2[0]) {
                                    case 'p':
                                    case 'u':
                                        // if this references a primary or unique constraint on the table then it is HAS_ONE
                                        $cn2 = $constraint2['column_name'];
                                        if ($cn2 === $cn) {
                                            $type = RelationSchema::HAS_ONE;
                                        }
                                        break;
                                    case 'f':
                                        // if other has foreign keys to other tables, we can say these are related as well
                                        $rts2 = array_get($constraint2, 'referenced_table_schema', '');
                                        $rtn2 = array_get($constraint2, 'referenced_table_name');
                                        if (!((0 === strcasecmp($rts2, $table->schemaName)) &&
                                            (0 === strcasecmp($rtn2, $table->resourceName)))
                                        ) {
                                            $name2 = ($rts2 == $defaultSchema) ? $rtn2 : $rts2 . '.' . $rtn2;
                                            $cn2 = array_get($constraint2, 'column_name');
                                            $rcn2 = array_get($constraint2, 'referenced_column_name');
                                            // not same as parent, i.e. via reference back to self
                                            // not the same key
                                            $relation =
                                                new RelationSchema([
                                                    'type'                => RelationSchema::MANY_MANY,
                                                    'field'               => $rcn,
                                                    'ref_service_id'      => $serviceId,
                                                    'ref_table'           => $name2,
                                                    'ref_field'           => $rcn2,
                                                    'ref_on_update'       => array_get($constraint, 'update_rule'),
                                                    'ref_on_delete'       => array_get($constraint, 'delete_rule'),
                                                    'junction_service_id' => $serviceId,
                                                    'junction_table'      => $name,
                                                    'junction_field'      => $cn,
                                                    'junction_ref_field'  => $cn2
                                                ]);

                                            $table->addRelation($relation);
                                        }
                                        break;
                                }
                            }

                            $relation = new RelationSchema([
                                'type'           => $type,
                                'field'          => $rcn,
                                'ref_service_id' => $serviceId,
                                'ref_table'      => $name,
                                'ref_field'      => $cn,
                                'ref_on_update'  => array_get($constraint, 'update_rule'),
                                'ref_on_delete'  => array_get($constraint, 'delete_rule'),
                            ]);

                            $table->addRelation($relation);
                        }
                    }
                }
            }
        }
    }

    protected function getApiDocRequests()
    {
        $add = [
            'TableSchemas'        => [
                'description' => 'TableSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchemas']
                    ],
                ],
            ],
            'TableSchema'         => [
                'description' => 'TableSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchema']
                    ],
                ],
            ],
            'FieldSchemas'        => [
                'description' => 'FieldSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchemas']
                    ],
                ],
            ],
            'FieldSchema'         => [
                'description' => 'FieldSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchema']
                    ],
                ],
            ],
            'RelationshipSchemas' => [
                'description' => 'RelationshipSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchemas']
                    ],
                ],
            ],
            'RelationshipSchema'  => [
                'description' => 'RelationshipSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchema']
                    ],
                ],
            ],
        ];

        return array_merge(parent::getApiDocRequests(), $add);
    }

    protected function getApiDocSchemas()
    {
        $wrapper = ResourcesWrapper::getWrapper();

        $add = [
            'TableSchemas'        => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'An array of table definitions.',
                        'items'       => [
                            '$ref' => '#/components/schemas/TableSchema',
                        ],
                    ],
                ],
            ],
            'TableSchema'         => TableSchema::getSchema(),
            'FieldSchemas'        => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'An array of field definitions.',
                        'items'       => [
                            '$ref' => '#/components/schemas/FieldSchema',
                        ],
                    ],
                ],
            ],
            'FieldSchema'         => ColumnSchema::getSchema(),
            'RelationshipSchemas' => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'An array of relationship definitions.',
                        'items'       => [
                            '$ref' => '#/components/schemas/RelationshipSchema',
                        ],
                    ],
                ],
            ],
            'RelationshipSchema'  => RelationSchema::getSchema(),
        ];

        return array_merge(parent::getApiDocSchemas(), $add);
    }

    protected function getApiDocResponses()
    {
        $add = [
            'TableSchemas'        => [
                'description' => 'TableSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchemas']
                    ],
                ],
            ],
            'TableSchema'         => [
                'description' => 'TableSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/TableSchema']
                    ],
                ],
            ],
            'FieldSchemas'        => [
                'description' => 'FieldSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchemas']
                    ],
                ],
            ],
            'FieldSchema'         => [
                'description' => 'FieldSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/FieldSchema']
                    ],
                ],
            ],
            'RelationshipSchemas' => [
                'description' => 'RelationshipSchemas',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchemas']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchemas']
                    ],
                ],
            ],
            'RelationshipSchema'  => [
                'description' => 'RelationshipSchema',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchema']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/RelationshipSchema']
                    ],
                ],
            ],
        ];

        return array_merge(parent::getApiDocResponses(), $add);
    }
}