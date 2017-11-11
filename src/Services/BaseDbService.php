<?php

namespace DreamFactory\Core\Database\Services;

use DreamFactory\Core\Contracts\DbExtrasInterface;
use DreamFactory\Core\Contracts\SchemaInterface;
use DreamFactory\Core\Database\Components\DbSchemaExtras;
use DreamFactory\Core\Database\Resources\BaseDbResource;
use DreamFactory\Core\Database\Resources\BaseDbTableResource;
use DreamFactory\Core\Database\Resources\DbSchemaResource;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\DbResourceTypes;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotImplementedException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Illuminate\Database\ConnectionInterface;

abstract class BaseDbService extends BaseRestService implements DbExtrasInterface
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
     * @var SchemaInterface
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

    protected static $resources = [
        DbSchemaResource::RESOURCE_NAME    => [
            'name'       => DbSchemaResource::RESOURCE_NAME,
            'class_name' => DbSchemaResource::class,
            'label'      => 'Schema',
        ],
        BaseDbTableResource::RESOURCE_NAME => [
            'name'       => BaseDbTableResource::RESOURCE_NAME,
            'class_name' => BaseDbTableResource::class,
            'label'      => 'Tables',
        ],
    ];

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
        return ($this->maxRecords > 0)? $this->maxRecords : $default;
    }

    public function upsertAllowed()
    {
        return $this->allowUpsert;
    }

    public function getCacheEnabled()
    {
        return $this->cacheEnabled;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $output = parent::getAccessList();
        $refresh = ($this->request ? $this->request->getParameterAsBool(ApiOptions::REFRESH) : false);
        $schema = ($this->request ? $this->request->getParameter(ApiOptions::SCHEMA, '') : false);

        foreach ($this->getResources(true) as $resourceInfo) {
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
     * @return SchemaInterface
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

    protected function getApiDocRequests()
    {
        $add = [
            'TableSchemas'  => [
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
            'TableSchema'   => [
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
            'FieldSchemas'  => [
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
            'FieldSchema'   => [
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
            'RelationshipSchemas'  => [
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
            'RelationshipSchema' => [
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
            'TableSchemas'  => [
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
            'TableSchema'   => [
                'type'       => 'object',
                'properties' => [
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'Identifier/Name for the table.',
                    ],
                    'label'       => [
                        'type'        => 'string',
                        'description' => 'Displayable singular name for the table.',
                    ],
                    'plural'      => [
                        'type'        => 'string',
                        'description' => 'Displayable plural name for the table.',
                    ],
                    'primary_key' => [
                        'type'        => 'string',
                        'description' => 'Field(s), if any, that represent the primary key of each record.',
                    ],
                    'name_field'  => [
                        'type'        => 'string',
                        'description' => 'Field(s), if any, that represent the name of each record.',
                    ],
                    'field'       => [
                        'type'        => 'array',
                        'description' => 'An array of available fields in each record.',
                        'items'       => [
                            '$ref' => '#/components/schemas/FieldSchema',
                        ],
                    ],
                    'related'     => [
                        'type'        => 'array',
                        'description' => 'An array of available relationships to other tables.',
                        'items'       => [
                            '$ref' => '#/components/schemas/RelationshipSchema',
                        ],
                    ],
                ],
            ],
            'FieldSchemas'  => [
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
            'FieldSchema'   => [
                'type'       => 'object',
                'properties' => [
                    'name'               => [
                        'type'        => 'string',
                        'description' => 'The API name of the field.',
                    ],
                    'label'              => [
                        'type'        => 'string',
                        'description' => 'The displayable label for the field.',
                    ],
                    'type'               => [
                        'type'        => 'string',
                        'description' => 'The DreamFactory abstract data type for this field.',
                    ],
                    'db_type'            => [
                        'type'        => 'string',
                        'description' => 'The native database type used for this field.',
                    ],
                    'length'             => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'The maximum length allowed (in characters for string, displayed for numbers).',
                    ],
                    'precision'          => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Total number of places for numbers.',
                    ],
                    'scale'              => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Number of decimal places allowed for numbers.',
                    ],
                    'default_value'      => [
                        'type'        => 'string',
                        'description' => 'Default value for this field.',
                    ],
                    'required'           => [
                        'type'        => 'boolean',
                        'description' => 'Is a value required for record creation.',
                    ],
                    'allow_null'         => [
                        'type'        => 'boolean',
                        'description' => 'Is null allowed as a value.',
                    ],
                    'fixed_length'       => [
                        'type'        => 'boolean',
                        'description' => 'Is the length fixed (not variable).',
                    ],
                    'supports_multibyte' => [
                        'type'        => 'boolean',
                        'description' => 'Does the data type support multibyte characters.',
                    ],
                    'auto_increment'     => [
                        'type'        => 'boolean',
                        'description' => 'Does the integer field value increment upon new record creation.',
                    ],
                    'is_primary_key'     => [
                        'type'        => 'boolean',
                        'description' => 'Is this field used as/part of the primary key.',
                    ],
                    'is_foreign_key'     => [
                        'type'        => 'boolean',
                        'description' => 'Is this field used as a foreign key.',
                    ],
                    'ref_table'          => [
                        'type'        => 'string',
                        'description' => 'For foreign keys, the referenced table name.',
                    ],
                    'ref_field'          => [
                        'type'        => 'string',
                        'description' => 'For foreign keys, the referenced table field name.',
                    ],
                    'validation'         => [
                        'type'        => 'array',
                        'description' => 'validations to be performed on this field.',
                        'items'       => [
                            'type' => 'string',
                        ],
                    ],
                    'value'              => [
                        'type'        => 'array',
                        'description' => 'Selectable string values for client menus and picklist validation.',
                        'items'       => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            'RelationshipSchemas'  => [
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
            'RelationshipSchema' => [
                'type'       => 'object',
                'properties' => [
                    'name'               => [
                        'type'        => 'string',
                        'description' => 'Name of the relationship.',
                    ],
                    'alias'               => [
                        'type'        => 'string',
                        'description' => 'Alias to use in the API to override the name the relationship.',
                    ],
                    'label'               => [
                        'type'        => 'string',
                        'description' => 'Label for the relationship.',
                    ],
                    'description'               => [
                        'type'        => 'string',
                        'description' => 'Description of the relationship.',
                    ],
                    'type'               => [
                        'type'        => 'string',
                        'description' => 'Relationship type - belongs_to, has_many, many_many.',
                    ],
                    'field'              => [
                        'type'        => 'string',
                        'description' => 'The current table field that is used in the relationship.',
                    ],
                    'ref_table'          => [
                        'type'        => 'string',
                        'description' => 'The table name that is referenced by the relationship.',
                    ],
                    'ref_field'          => [
                        'type'        => 'string',
                        'description' => 'The field name that is referenced by the relationship.',
                    ],
                    'junction_table'     => [
                        'type'        => 'string',
                        'description' => 'The intermediate junction table used for many_many relationships.',
                    ],
                    'junction_field'     => [
                        'type'        => 'string',
                        'description' => 'The intermediate junction table field used for many_many relationships.',
                    ],
                    'junction_ref_field' => [
                        'type'        => 'string',
                        'description' => 'The intermediate joining table referencing field used for many_many relationships.',
                    ],
                    'always_fetch' => [
                        'type'        => 'boolean',
                        'description' => 'Always fetch this relationship when querying the parent table.',
                    ],
                ],
            ],
        ];

        return array_merge(parent::getApiDocSchemas(), $add);
    }

    protected function getApiDocResponses()
    {
        $add = [
            'TableSchemas'  => [
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
            'TableSchema'   => [
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
            'FieldSchemas'  => [
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
            'FieldSchema'   => [
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
            'RelationshipSchemas'  => [
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
            'RelationshipSchema' => [
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