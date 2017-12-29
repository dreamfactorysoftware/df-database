<?php

namespace DreamFactory\Core\Database\Resources;

use DreamFactory\Core\Components\DataValidator;
use DreamFactory\Core\Database\Components\TableDescriber;
use DreamFactory\Core\Database\Enums\DbFunctionUses;
use DreamFactory\Core\Database\Enums\FunctionTypes;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\DbResourceTypes;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\GraphQL\Contracts\GraphQLHandlerInterface;
use DreamFactory\Core\GraphQL\Query\ServiceMultiResourceQuery;
use DreamFactory\Core\GraphQL\Query\ServiceResourceListQuery;
use DreamFactory\Core\GraphQL\Query\ServiceSingleResourceQuery;
use DreamFactory\Core\GraphQL\Type\BaseType;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ResourcesWrapper;
use GraphQL\Type\Definition\Type;
use ServiceManager;

class DbSchemaResource extends BaseDbResource implements GraphQLHandlerInterface
{
    use DataValidator, TableDescriber;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with table schema
     */
    const RESOURCE_NAME = '_schema';
    /**
     * Replacement tag for dealing with table schema events
     */
    const EVENT_IDENTIFIER = '{table_name}';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var mixed Resource ID.
     */
    protected $resourceId2;
    /**
     * @var array
     */
    protected $fieldExtras = [
        'alias',
        'label',
        'description',
        'picklist',
        'validation',
        'client_info',
        'db_function',
        'is_virtual',
        'is_aggregate',
    ];
    /**
     * @var array
     */
    protected $relatedExtras = [
        'alias',
        'label',
        'description',
        'always_fetch',
        'flatten',
        'flatten_drop_prefix',
    ];

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
        $result = $this->parent->getTableNames($schema, $refresh);
        $resources = [];
        foreach ($result as $table) {
            if (!empty($this->getPermissions($table->name))) {
                $resources[] = $table->name;
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
        $schema = $this->request->getParameter(ApiOptions::SCHEMA, '');
        $ids = static::validateAsArray($this->request->getParameter(ApiOptions::IDS), ',');
        $result = $this->parent->getTableNames($schema, $refresh);
        $resources = [];
        foreach ($result as $table) {
            $name = $table->name;
            if ((false !== $ids) && !empty($ids)) {
                if (false === array_search($name, $ids)) {
                    continue;
                }
            }
            $access = $this->getPermissions($table->name);
            if (!empty($access)) {
                $info = $table->toArray();
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
    public function refreshCachedTables()
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
     * @param string $table
     * @param string $action
     *
     * @throws BadRequestException
     * @throws \DreamFactory\Core\Exceptions\ForbiddenException
     */
    protected function validateSchemaAccess($table, $action = null)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        $this->doesTableExist($table);
        $this->checkPermission($action, $table);
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
        // Try the generic table event
        parent::firePreProcessEvent($name, $resource);

        // also fire more specific event
        // Try the actual table name event
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
        // Try the generic table event
        parent::firePostProcessEvent($name, $resource);

        // also fire more specific event
        // Try the actual table name event
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
        // Try the generic table event
        parent::fireFinalEvent($name, $resource);

        // also fire more specific event
        // Try the actual table name event
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

    /**
     * @return array|bool
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handleGET()
    {
        $refresh = $this->request->getParameterAsBool(ApiOptions::REFRESH);
        $payload = $this->request->getPayloadData();
        $ids = array_get($payload, ApiOptions::IDS, $this->request->getParameter(ApiOptions::IDS));
        if (empty($ids)) {
            $ids = ResourcesWrapper::unwrapResources($this->request->getPayloadData());
        }

        if (empty($this->resource)) {
            if (!empty($ids)) {
                $result = $this->describeTables($ids, $refresh);
                $result = ResourcesWrapper::wrapResources($result);
            } else {
                $result = parent::handleGET();
            }
        } else {
            if (false === ($tableName = $this->doesTableExist($this->resource, true))) {
                throw new NotFoundException('Table "' . $this->resource . '" does not exist in the database.');
            }

            if (empty($this->resourceId)) {
                $result = $this->describeTable($tableName, $refresh);
            } elseif (empty($this->resourceId2)) {
                switch ($this->resourceId) {
                    case '_field':
                        $result = $this->describeFields($tableName, $ids, $refresh);
                        $result = ResourcesWrapper::wrapResources($result);
                        break;
                    case '_related':
                        $result = $this->describeRelationships($tableName, $ids, $refresh);
                        $result = ResourcesWrapper::wrapResources($result);
                        break;
                    default:
                        // deprecated field describe
                        $result = $this->describeField($tableName, $this->resourceId, $refresh);
                        break;
                }
            } else {
                switch ($this->resourceId) {
                    case '_field':
                        $result = $this->describeField($tableName, $this->resourceId2, $refresh);
                        break;
                    case '_related':
                        $result = $this->describeRelationship($tableName, $this->resourceId2, $refresh);
                        break;
                    default:
                        throw new BadRequestException('Invalid schema path in describe request.');
                        break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        $checkExist = $this->request->getParameterAsBool('check_exist');
        $fields = $this->request->getParameter(ApiOptions::FIELDS);
        if (empty($this->resource)) {
            $payload = ResourcesWrapper::unwrapResources($payload);
            if (empty($payload)) {
                throw new BadRequestException('No data in schema create request.');
            }

            $result = $this->createTables($payload, $checkExist, $fields);
            $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
            $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
            $result = ResourcesWrapper::cleanResources($result, $asList, $idField, $fields);
        } else {
            if (false === ($tableName = $this->doesTableExist($this->resource, true))) {
                throw new NotFoundException('Table "' . $this->resource . '" does not exist in the database.');
            }

            if (empty($this->resourceId)) {
                $result = $this->createTable($tableName, $payload, $checkExist, $fields);
            } elseif (empty($this->resourceId2)) {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema create request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema create request.');
                        }

                        $result = $this->createFields($tableName, $payload, $checkExist, $fields);
                        break;
                    case '_related':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema create request.');
                        }

                        $result = $this->createRelationships($tableName, $payload, $checkExist, $fields);
                        break;
                    default:
                        // deprecated field create
                        $result = $this->createField($tableName, $this->resourceId, $payload, $checkExist, $fields);
                        break;
                }
            } else {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema create request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $result = $this->createField($tableName, $this->resourceId2, $payload, $checkExist, $fields);
                        break;
                    case '_related':
                        $result = $this->createRelationship($tableName, $this->resourceId2, $payload, $checkExist,
                            $fields);
                        break;
                    default:
                        throw new BadRequestException('Invalid schema path in create request.');
                        break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        $fields = $this->request->getParameter(ApiOptions::FIELDS);
        if (empty($this->resource)) {
            $payload = ResourcesWrapper::unwrapResources($payload);
            if (empty($payload)) {
                throw new BadRequestException('No data in schema update request.');
            }

            $result = $this->updateTables($payload, true, $fields);
            $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
            $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
            $result = ResourcesWrapper::cleanResources($result, $asList, $idField, $fields);
        } else {
            if (false === ($tableName = $this->doesTableExist($this->resource, true))) {
                throw new NotFoundException('Table "' . $this->resource . '" does not exist in the database.');
            }

            if (empty($this->resourceId)) {
                $result = $this->updateTable($tableName, $payload, true, $fields);
            } elseif (empty($this->resourceId2)) {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema update request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema update request.');
                        }

                        $result = $this->updateFields($tableName, $payload, true, $fields);
                        break;
                    case '_related':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema update request.');
                        }

                        $result = $this->updateRelationships($tableName, $payload, true, $fields);
                        break;
                    default:
                        // deprecated field update
                        $result = $this->updateField($tableName, $this->resourceId, $payload, true, $fields);
                        break;
                }
            } else {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema update request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $result = $this->updateField($tableName, $this->resourceId2, $payload, true, $fields);
                        break;
                    case '_related':
                        $result = $this->updateRelationship($tableName, $this->resourceId2, $payload, true, $fields);
                        break;
                    default:
                        throw new BadRequestException('Invalid schema path in update request.');
                        break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handlePATCH()
    {
        $payload = $this->request->getPayloadData();
        $fields = $this->request->getParameter(ApiOptions::FIELDS);
        if (empty($this->resource)) {
            $tables = ResourcesWrapper::unwrapResources($payload);
            if (empty($tables)) {
                throw new BadRequestException('No data in schema update request.');
            }

            $result = $this->updateTables($tables, false, $fields);
            $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
            $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
            $result = ResourcesWrapper::cleanResources($result, $asList, $idField, $fields);
        } else {
            if (false === ($tableName = $this->doesTableExist($this->resource, true))) {
                throw new NotFoundException('Table "' . $this->resource . '" does not exist in the database.');
            }

            if (empty($this->resourceId)) {
                $result = $this->updateTable($tableName, $payload, false, $fields);
            } elseif (empty($this->resourceId2)) {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema update request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema update request.');
                        }

                        $result = $this->updateFields($tableName, $payload, false, $fields);
                        break;
                    case '_related':
                        $payload = ResourcesWrapper::unwrapResources($payload);
                        if (empty($payload)) {
                            throw new BadRequestException('No data in schema update request.');
                        }

                        $result = $this->updateRelationships($tableName, $payload, false, $fields);
                        break;
                    default:
                        // deprecated field update
                        $result = $this->updateField($tableName, $this->resourceId, $payload, false, $fields);
                        break;
                }
            } else {
                if (empty($payload)) {
                    throw new BadRequestException('No data in schema update request.');
                }

                switch ($this->resourceId) {
                    case '_field':
                        $result = $this->updateField($tableName, $this->resourceId2, $payload, false, $fields);
                        break;
                    case '_related':
                        $result = $this->updateRelationship($tableName, $this->resourceId2, $payload, false, $fields);
                        break;
                    default:
                        throw new BadRequestException('Invalid schema path in update request.');
                        break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handleDELETE()
    {
        $payload = $this->request->getPayloadData();
        $fields = $this->request->getParameter(ApiOptions::FIELDS);
        if (empty($this->resource)) {
            $tables = $this->request->getParameter(ApiOptions::IDS);
            if (empty($tables)) {
                $tables = ResourcesWrapper::unwrapResources($payload);
            }

            if (empty($tables)) {
                throw new BadRequestException('No data in schema delete request.');
            }

            $result = $this->deleteTables($tables);
            $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
            $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
            $result = ResourcesWrapper::cleanResources($result, $asList, $idField, $fields);
        } else {
            if (false === ($tableName = $this->doesTableExist($this->resource, true))) {
                throw new NotFoundException('Table "' . $this->resource . '" does not exist in the database.');
            }

            if (empty($this->resourceId)) {
                // delete the table
                $this->deleteTable($tableName);
            } elseif (empty($this->resourceId2)) {
                switch ($this->resourceId) {
                    case '_field':
                        $ids = array_get($payload, ApiOptions::IDS, $this->request->getParameter(ApiOptions::IDS));
                        if (empty($ids)) {
                            if (empty($ids = ResourcesWrapper::unwrapResources($payload))) {
                                throw new BadRequestException('No data in schema delete request.');
                            }
                        }

                        $this->deleteFields($tableName, $ids);
                        break;
                    case '_related':
                        $ids = array_get($payload, ApiOptions::IDS, $this->request->getParameter(ApiOptions::IDS));
                        if (empty($ids)) {
                            if (empty($ids = ResourcesWrapper::unwrapResources($payload))) {
                                throw new BadRequestException('No data in schema delete request.');
                            }
                        }

                        $this->deleteRelationships($tableName, $ids);
                        break;
                    default:
                        // deprecated field delete
                        $this->deleteField($tableName, $this->resourceId);
                        break;
                }
            } else {
                switch ($this->resourceId) {
                    case '_field':
                        $this->deleteField($tableName, $this->resourceId2);
                        break;
                    case '_related':
                        $this->deleteRelationship($tableName, $this->resourceId2);
                        break;
                    default:
                        throw new BadRequestException('Invalid schema path in delete request.');
                        break;
                }
            }

            $result = ['success' => true];
        }

        return $result;
    }

    /**
     * Get multiple tables and their properties
     *
     * @param string | array $tables  Table names comma-delimited string or array
     * @param bool           $refresh Force a refresh of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function describeTables($tables, $refresh = false)
    {
        $tables = static::validateAsArray(
            $tables,
            ',',
            true,
            'The request contains no valid table names or properties.'
        );

        $out = [];
        foreach ($tables as $table) {
            $name = (is_array($table)) ? array_get($table, 'name') : $table;
            $this->validateSchemaAccess($name, Verbs::GET);

            $out[] = $this->describeTable($table, $refresh);
        }

        return $out;
    }

    /**
     * Get any properties related to the table
     *
     * @param string | array $name    Table name or defining properties
     * @param bool           $refresh Force a refresh of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function describeTable($name, $refresh = false)
    {
        $name = (is_array($name) ? array_get($name, 'name') : $name);
        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $table = $this->parent->getTableSchema($name, $refresh);
            if (!$table) {
                throw new NotFoundException("Table '$name' does not exist in the database.");
            }

            $result = $table->toArray();
            $result['access'] = $this->getPermissions($name);

            return $result;
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to query database schema.\n{$ex->getMessage()}");
        }
    }

    /**
     * Describe multiple table fields
     *
     * @param string $table
     * @param array  $fields
     * @param bool   $refresh Force a refresh of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function describeFields($table, $fields, $refresh = false)
    {
        if (empty($fields)) {
            $out = $this->describeTable($table, $refresh);

            return array_get($out, 'field');
        }

        $fields = static::validateAsArray(
            $fields,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($fields as $field) {
            $name = (is_array($field)) ? array_get($field, 'name') : $field;
            $this->validateSchemaAccess($table, Verbs::GET);
            $out[] = $this->describeField($table, $name, $refresh);
        }

        return $out;
    }

    /**
     * Get any properties related to the table field
     *
     * @param string $table   Table name
     * @param string $field   Table field name
     * @param bool   $refresh Force a refresh of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function describeField($table, $field, $refresh = false)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $result = $this->describeTableFields($table, $field, $refresh);

            return array_get($result, 0);
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Error describing database table '$table' field '$field'.\n" .
                $ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * Describe multiple table fields
     *
     * @param string $table
     * @param array  $relationships
     * @param bool   $refresh Force a refresh of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function describeRelationships($table, $relationships, $refresh = false)
    {
        if (empty($fields)) {
            $out = $this->describeTable($table, $refresh);

            return array_get($out, 'related');
        }

        $relationships = static::validateAsArray(
            $relationships,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($relationships as $relationship) {
            $name = (is_array($relationship)) ? array_get($relationship, 'name') : $relationship;
            $this->validateSchemaAccess($table, Verbs::GET);
            $out[] = $this->describeRelationship($table, $name, $refresh);
        }

        return $out;
    }

    /**
     * Get any properties related to the table field
     *
     * @param string $table        Table name
     * @param string $relationship Table relationship name
     * @param bool   $refresh      Force a refresh of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function describeRelationship($table, $relationship, $refresh = false)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $result = $this->describeTableRelationships($table, $relationship, $refresh);

            return array_get($result, 0);
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Error describing database table '$table' relationship '$relationship'.\n" .
                $ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * Create one or more tables by array of table properties
     *
     * @param string|array $tables
     * @param bool         $check_exist
     * @param bool         $return_schema Return a refreshed copy of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function createTables($tables, $check_exist = false, $return_schema = false)
    {
        $tables = static::validateAsArray($tables, null, true, 'There are no table sets in the request.');

        foreach ($tables as $table) {
            if (null === ($name = array_get($table, 'name'))) {
                throw new BadRequestException("Table schema received does not have a valid name.");
            }
        }

        $result = $this->updateSchema($tables, !$check_exist);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeTables($tables);
        }

        return $result;
    }

    /**
     * Create a single table by name and additional properties
     *
     * @param string $table
     * @param array  $properties
     * @param bool   $check_exist
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     * @return array|mixed
     * @throws BadRequestException
     * @throws \Exception
     */
    public function createTable($table, $properties = [], $check_exist = false, $return_schema = false)
    {
        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $table;

        $tables = static::validateAsArray($properties, null, true, 'Bad data format in request.');
        $result = $this->updateSchema($tables, !$check_exist);
        $result = array_get($result, 0, []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeTable($table);
        }

        return $result;
    }

    /**
     * Create multiple table fields
     *
     * @param string $table
     * @param array  $fields
     * @param bool   $check_exist
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function createFields($table, $fields, $check_exist = false, $return_schema = false)
    {
        $fields = static::validateAsArray(
            $fields,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($fields as $field) {
            $name = (is_array($field)) ? array_get($field, 'name') : $field;
            $this->validateSchemaAccess($table, Verbs::PUT);
            $out[] = $this->createField($table, $name, $field, $check_exist, $return_schema);
        }

        return $out;
    }

    /**
     * Create a single table field by name and additional properties
     *
     * @param string $table
     * @param string $field
     * @param array  $properties
     * @param bool   $check_exist
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     * @return array|mixed
     * @throws BadRequestException
     * @throws \Exception
     */
    public function createField($table, $field, $properties = [], $check_exist = false, $return_schema = false)
    {
        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $field;

        $fields = static::validateAsArray($properties, null, true, 'Bad data format in request.');

        $tables = [['name' => $table, 'field' => $fields]];
        $result = $this->updateSchema($tables, !$check_exist);
        $result = array_get(array_get($result, 0, []), 'field', []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeField($table, $field);
        }

        return $result;
    }

    /**
     * Create multiple table relationships
     *
     * @param string $table
     * @param array  $relationships
     * @param bool   $check_exist
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function createRelationships($table, $relationships, $check_exist = false, $return_schema = false)
    {
        $relationships = static::validateAsArray(
            $relationships,
            ',',
            true,
            'The request contains no valid table relationship names or properties.'
        );

        $out = [];
        foreach ($relationships as $relationship) {
            $name = (is_array($relationship)) ? array_get($relationship, 'name') : $relationship;
            $this->validateSchemaAccess($table, Verbs::PUT);
            $out[] = $this->createRelationship($table, $name, $relationship, $check_exist, $return_schema);
        }

        return $out;
    }

    /**
     * Create a single table relationship by name and additional properties
     *
     * @param string $table
     * @param string $relationship
     * @param array  $properties
     * @param bool   $check_exist
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     * @return array|mixed
     * @throws BadRequestException
     * @throws \Exception
     */
    public function createRelationship(
        $table,
        $relationship,
        $properties = [],
        $check_exist = false,
        $return_schema = false
    ) {
        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $relationship;

        $fields = static::validateAsArray($properties, null, true, 'Bad data format in request.');

        $tables = [['name' => $table, 'related' => $fields]];
        $result = $this->updateSchema($tables, !$check_exist);
        $result = array_get(array_get($result, 0, []), 'related', []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeRelationship($table, $relationship);
        }

        return $result;
    }

    /**
     * Update one or more tables by array of table properties
     *
     * @param array $tables
     * @param bool  $allow_delete_fields
     * @param bool  $return_schema Return a refreshed copy of the schema from the database
     * @return array
     * @throws BadRequestException
     * @throws \DreamFactory\Core\Exceptions\ForbiddenException
     * @throws \Exception
     */
    public function updateTables($tables, $allow_delete_fields = false, $return_schema = false)
    {
        $tables = static::validateAsArray($tables, null, true, 'There are no table sets in the request.');

        foreach ($tables as $table) {
            $name = (is_array($table)) ? array_get($table, 'name') : $table;
            if (empty($name)) {
                throw new BadRequestException("Table schema received does not have a valid name.");
            }
            if ($this->doesTableExist($name)) {
                $this->validateSchemaAccess($name, Verbs::PATCH);
            } else {
                $this->validateSchemaAccess(null, Verbs::POST);
            }
        }

        $result = $this->updateSchema($tables, true, $allow_delete_fields);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeTables($tables);
        }

        return $result;
    }

    /**
     * Update properties related to the table
     *
     * @param string $table
     * @param array  $properties
     * @param bool   $allow_delete_fields
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function updateTable($table, $properties, $allow_delete_fields = false, $return_schema = false)
    {
        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $table;

        $tables = static::validateAsArray($properties, null, true, 'Bad data format in request.');

        $result = $this->updateSchema($tables, true, $allow_delete_fields);
        $result = array_get($result, 0, []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeTable($table);
        }

        return $result;
    }

    /**
     * Update multiple table fields
     *
     * @param string $table
     * @param array  $fields
     * @param bool   $allow_delete_parts
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function updateFields($table, $fields, $allow_delete_parts = false, $return_schema = false)
    {
        $fields = static::validateAsArray(
            $fields,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($fields as $field) {
            $name = (is_array($field)) ? array_get($field, 'name') : $field;
            $this->validateSchemaAccess($table, Verbs::PUT);
            $out[] = $this->updateField($table, $name, $field, $allow_delete_parts, $return_schema);
        }

        return $out;
    }

    /**
     * Update properties related to a table field
     *
     * @param string $table
     * @param string $field
     * @param array  $properties
     * @param bool   $allow_delete_parts
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function updateField($table, $field, $properties = [], $allow_delete_parts = false, $return_schema = false)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $field;

        $fields = static::validateAsArray($properties, null, true, 'Bad data format in request.');

        $tables = [['name' => $table, 'field' => $fields]];
        $result = $this->updateSchema($tables, true, false);
        $result = array_get(array_get($result, 0, []), 'field', []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeField($table, $field);
        }

        return $result;
    }

    /**
     * Update multiple table relationships
     *
     * @param string $table
     * @param array  $relationships
     * @param bool   $allow_delete_parts
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @throws \Exception
     * @return array
     */
    public function updateRelationships($table, $relationships, $allow_delete_parts = false, $return_schema = false)
    {
        $relationships = static::validateAsArray(
            $relationships,
            ',',
            true,
            'The request contains no valid table relationship names or properties.'
        );

        $out = [];
        foreach ($relationships as $relationship) {
            $name = (is_array($relationship)) ? array_get($relationship, 'name') : $relationship;
            $this->validateSchemaAccess($table, Verbs::PUT);
            $out[] = $this->updateRelationship($table, $name, $relationship, $allow_delete_parts, $return_schema);
        }

        return $out;
    }

    /**
     * Update properties related to a table relationship
     *
     * @param string $table
     * @param string $relationship
     * @param array  $properties
     * @param bool   $allow_delete_parts
     * @param bool   $return_schema Return a refreshed copy of the schema from the database
     *
     * @return array
     * @throws \Exception
     */
    public function updateRelationship(
        $table,
        $relationship,
        $properties = [],
        $allow_delete_parts = false,
        $return_schema = false
    ) {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        $properties = (is_array($properties) ? $properties : []);
        $properties['name'] = $relationship;

        $fields = static::validateAsArray($properties, null, true, 'Bad data format in request.');

        $tables = [['name' => $table, 'related' => $fields]];
        $result = $this->updateSchema($tables, true, false);
        $result = array_get(array_get($result, 0, []), 'related', []);

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        if ($return_schema) {
            return $this->describeRelationship($table, $relationship);
        }

        return $result;
    }

    /**
     * Delete multiple tables and all of their contents
     *
     * @param array $tables
     * @param bool  $check_empty
     *
     * @return array
     * @throws \Exception
     */
    public function deleteTables($tables, $check_empty = false)
    {
        $tables = static::validateAsArray(
            $tables,
            ',',
            true,
            'The request contains no valid table names or properties.'
        );

        $out = [];
        foreach ($tables as $table) {
            $name = (is_array($table)) ? array_get($table, 'name') : $table;
            $this->validateSchemaAccess($name, Verbs::DELETE);
            $out[] = $this->deleteTable($name, $check_empty);
        }

        return $out;
    }

    /**
     * Delete a table and all of its contents by name
     *
     * @param string $table
     * @param bool   $check_empty
     *
     * @throws \Exception
     * @return array
     */
    public function deleteTable($table, $check_empty = false)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        //  Does it exist
        if (!$this->doesTableExist($table)) {
            throw new NotFoundException("Table '$table' not found.");
        }

        if ($check_empty) {
            // todo exist query here
        }
        try {
            if ($resource = $this->parent->getTableSchema($table)) {
                $this->parent->getSchema()->dropTable($resource->quotedName);
                $this->tablesDropped($table);
            }
        } catch (\Exception $ex) {
            \Log::error('Exception dropping table: ' . $ex->getMessage());

            throw $ex;
        }

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        return ['name' => $table];
    }

    /**
     * Delete multiple table fields
     *
     * @param string $table
     * @param array  $fields
     *
     * @throws \Exception
     * @return array
     */
    public function deleteFields($table, $fields)
    {
        $fields = static::validateAsArray(
            $fields,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($fields as $field) {
            $name = (is_array($field)) ? array_get($field, 'name') : $field;
            $this->validateSchemaAccess($table, Verbs::DELETE);
            $out[] = $this->deleteField($table, $name);
        }

        return $out;
    }

    /**
     * Delete a table field
     *
     * @param string $table
     * @param string $field
     *
     * @throws \Exception
     * @return array
     */
    public function deleteField($table, $field)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        // does it already exist
        if (!$this->doesTableExist($table)) {
            throw new NotFoundException("A table with name '$table' does not exist in the database.");
        }

        try {
            $tableSchema = $this->parent->getTableSchema($table);
            if ($resource = $tableSchema->getColumn($field)) {
                if ($this->parent->getSchema()->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD) && !$resource->isVirtual) {
                    $this->parent->getSchema()->dropColumns($tableSchema->quotedName, $resource->quotedName);
                }
                $this->fieldsDropped($table, $field);
            }
        } catch (\Exception $ex) {
            error_log($ex->getMessage());
            throw $ex;
        }

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        return ['name' => $field];
    }

    /**
     * Delete multiple table fields
     *
     * @param string $table
     * @param array  $relationships
     *
     * @throws \Exception
     * @return array
     */
    public function deleteRelationships($table, $relationships)
    {
        $relationships = static::validateAsArray(
            $relationships,
            ',',
            true,
            'The request contains no valid table field names or properties.'
        );

        $out = [];
        foreach ($relationships as $relationship) {
            $name = (is_array($relationship)) ? array_get($relationship, 'name') : $relationship;
            $this->validateSchemaAccess($table, Verbs::DELETE);
            $out[] = $this->deleteRelationship($table, $name);
        }

        return $out;
    }

    /**
     * Delete a table field
     *
     * @param string $table
     * @param string $relationship
     *
     * @throws \Exception
     * @return array
     */
    public function deleteRelationship($table, $relationship)
    {
        if (empty($table)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        // does it already exist
        if (!$this->doesTableExist($table)) {
            throw new NotFoundException("A table with name '$table' does not exist in the database.");
        }

        try {
            $tableSchema = $this->parent->getTableSchema($table);
            if ($resource = $tableSchema->getRelation($relationship)) {
                if ($resource->isVirtual) {
                    $this->removeSchemaVirtualRelationships($table, [$resource->toArray()]);
                } else {
                    $this->parent->getSchema()->dropRelationship($tableSchema->quotedName, $resource);
                }
                $this->removeSchemaExtrasForRelated($table, $relationship);
            }
        } catch (\Exception $ex) {
            error_log($ex->getMessage());
            throw $ex;
        }

        //  Any changes here should refresh cached schema
        $this->refreshCachedTables();

        return ['name' => $relationship];
    }

    /**
     * @param string $name
     *
     * @return string
     * @throws NotFoundException
     * @throws \Exception
     */
    public function correctTableName(&$name)
    {
        if (false !== ($table = $this->doesTableExist($name, true))) {
            $name = $table;

            return $name;
        } else {
            throw new NotFoundException('Table "' . $name . '" does not exist in the database.');
        }
    }

    /**
     * @param string $name       The name of the table to check
     * @param bool   $returnName If true, the table name is returned instead of TRUE
     *
     * @return bool
     * @throws \Exception
     */
    public function doesTableExist($name, $returnName = false)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Table name cannot be empty.');
        }

        $tables = $this->parent->getTableNames();
        //	Search normal, return real name
        $ndx = strtolower($name);
        if (isset($tables[$ndx])) {
            return $returnName ? $tables[$ndx]->name : true;
        }

        return false;
    }


    /**
     * @param string                $table_name
     * @param null | string | array $field_names
     * @param bool                  $refresh
     *
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function describeTableFields($table_name, $field_names = null, $refresh = false)
    {
        $table = $this->parent->getTableSchema($table_name, $refresh);
        if (!$table) {
            throw new NotFoundException("Table '$table_name' does not exist in the database.");
        }

        if (!empty($field_names)) {
            $field_names = static::validateAsArray($field_names, ',', true, 'No valid field names given.');
        }

        $out = [];
        try {
            /** @var ColumnSchema $column */
            foreach ($table->columns as $column) {
                if (empty($field_names) || (false !== array_search($column->name, $field_names))) {
                    $out[] = $column->toArray();
                }
            }
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to query table field schema.\n{$ex->getMessage()}");
        }

        if (empty($out)) {
            throw new NotFoundException("No requested fields found in table '$table_name'.");
        }

        return $out;
    }

    /**
     * @param string                $table_name
     * @param null | string | array $relationships
     * @param bool                  $refresh
     *
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function describeTableRelationships($table_name, $relationships = null, $refresh = false)
    {
        /** @var TableSchema $table */
        $table = $this->parent->getTableSchema($table_name, $refresh);
        if (!$table) {
            throw new NotFoundException("Table '$table_name' does not exist in the database.");
        }

        if (!empty($relationships)) {
            $relationships = static::validateAsArray($relationships, ',', true, 'No valid relationship names given.');
        }

        $out = [];
        try {
            /** @var RelationSchema $relation */
            foreach ($table->relations as $relation) {
                if (empty($relationships) || (false !== array_search($relation->name, $relationships))) {
                    $out[] = $relation->toArray();
                }
            }
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to query table relationship schema.\n{$ex->getMessage()}");
        }

        if (empty($out)) {
            throw new NotFoundException("No requested relationships found in table '$table_name'.");
        }

        return $out;
    }

    /**
     * @param      $tables
     * @param bool $allow_merge
     * @param bool $allow_delete
     * @param bool $rollback
     *
     * @return array
     * @throws \Exception
     */
    public function updateSchema($tables, $allow_merge = false, $allow_delete = false, $rollback = false)
    {
        if (!is_array($tables) || empty($tables)) {
            throw new \Exception('There are no table sets in the request.');
        }

        if (!isset($tables[0])) {
            // single record possibly passed in without wrapper array
            $tables = [$tables];
        }

        $created = [];
        $references = [];
        $indexes = [];
        $out = [];
        $tableExtras = [];
        $fieldExtras = [];
        $fieldDrops = [];
        $relatedExtras = [];
        $relatedDrops = [];
        $virtualRelations = [];
        $virtualRelationDrops = [];
        $count = 0;
        $singleTable = (1 == count($tables));
        $schema = $this->parent->getSchema();

        foreach ($tables as $table) {
            try {
                if (empty($tableName = array_get($table, 'name'))) {
                    throw new \Exception('Table name missing from schema.');
                }

                //	Does it already exist
                if ($tableSchema = $this->parent->getTableSchema($tableName)) {
                    if (!$allow_merge) {
                        throw new \Exception("A table with name '$tableName' already exist in the database.");
                    }

                    \Log::debug('Schema update: ' . $tableName);

                    $results = [];
                    if (!empty($fields = array_get($table, 'field'))) {
                        $results = $this->buildTableFields($tableSchema, $fields, true, $allow_delete);
                    }
                    if (!empty($related = array_get($table, 'related'))) {
                        $related = $this->buildTableRelated($tableSchema, $related, true, $allow_delete);
                        $results = array_merge($results, $related);
                    }

                    $schema->updateTable($tableSchema, array_merge($table, $results));
                } else {
                    \Log::debug('Creating table: ' . $tableName);

                    $results = [];
                    if (!empty($fields = array_get($table, 'field'))) {
                        $results = $this->createTableFields($tableName, $fields);
                    }
                    if (!empty($related = array_get($table, 'related'))) {
                        $temp = $this->createTableRelated($tableName, $related);
                        $results = array_merge($results, $temp);
                    }

                    $schema->createTable($table, $results);

                    if (!$singleTable && $rollback) {
                        $created[] = $tableName;
                    }
                }

                if (!empty($results['commands'])) {
                    foreach ($results['commands'] as $extraCommand) {
                        try {
                            $this->parent->getConnection()->statement($extraCommand);
                        } catch (\Exception $ex) {
                            // oh well, we tried.
                        }
                    }
                }

                // add table extras
                $extras = array_only($table, ['label', 'plural', 'alias', 'description', 'name_field']);
                if (!empty($extras)) {
                    $extras['table'] = $tableName;
                    $tableExtras[] = $extras;
                }

                $fieldExtras = array_merge($fieldExtras, (array)array_get($results, 'extras'));
                $fieldDrops = array_merge($fieldDrops, (array)array_get($results, 'drop_extras'));
                $references = array_merge($references, (array)array_get($results, 'references'));
                $indexes = array_merge($indexes, (array)array_get($results, 'indexes'));
                $relatedExtras = array_merge($relatedExtras, (array)array_get($results, 'related_extras'));
                $relatedDrops = array_merge($relatedDrops, (array)array_get($results, 'drop_related_extras'));
                $virtualRelations = array_merge($virtualRelations, (array)array_get($results, 'virtual_relations'));
                $virtualRelationDrops = array_merge($virtualRelationDrops,
                    (array)array_get($results, 'drop_virtual_relations'));

                $out[$count] = ['name' => $tableName];
            } catch (\Exception $ex) {
                if ($rollback || $singleTable) {
                    //  Delete any created tables
                    throw $ex;
                }

                $out[$count] = [
                    'error' => [
                        'message' => $ex->getMessage(),
                        'code'    => $ex->getCode()
                    ]
                ];
            }

            $count++;
        }

        if (!empty($references)) {
            $schema->createFieldReferences($references);
        }
        if (!empty($indexes)) {
            $schema->createFieldIndexes($indexes);
        }
        if (!empty($tableExtras)) {
            $this->setSchemaTableExtras($tableExtras);
        }
        if (!empty($fieldExtras)) {
            $this->setSchemaFieldExtras($fieldExtras);
        }
        if (!empty($fieldDrops)) {
            foreach ($fieldDrops as $table => $dropped) {
                $this->removeSchemaExtrasForFields($table, $dropped);
            }
        }
        if (!empty($relatedExtras)) {
            $this->setSchemaRelatedExtras($relatedExtras);
        }
        if (!empty($relatedDrops)) {
            foreach ($relatedDrops as $table => $dropped) {
                $this->removeSchemaExtrasForRelated($table, $dropped);
            }
        }
        if (!empty($virtualRelations)) {
            $this->setSchemaVirtualRelationships($virtualRelations);
        }
        if (!empty($virtualRelationDrops)) {
            foreach ($virtualRelationDrops as $table => $dropped) {
                $this->removeSchemaVirtualRelationships($table, $dropped);
            }
        }

        return $out;
    }

    /**
     * @param string $table_name
     * @param array  $fields
     *
     * @throws \Exception
     * @return array
     */
    protected function createTableFields($table_name, $fields)
    {
        if (!is_array($fields) || empty($fields)) {
            throw new \Exception('There are no fields in the requested schema.');
        }

        if (!isset($fields[0])) {
            // single record possibly passed in without wrapper array
            $fields = [$fields];
        }

        $schema = $this->parent->getSchema();

        $internalTableName = $table_name;
        if ((false === strpos($table_name, '.')) && !empty($namingSchema = $this->parent->getNamingSchema())) {
            $internalTableName = $namingSchema . '.' . $table_name;
        }
        $columns = [];
        $references = [];
        $indexes = [];
        $extras = [];
        $commands = [];
        foreach ($fields as $field) {
            $this->cleanClientField($field);
            $name = array_get($field, 'name');

            // clean out extras
            $extraNew = array_only($field, $this->fieldExtras);
            $field = array_except($field, $this->fieldExtras);

            $type = strtolower((string)array_get($field, 'type'));
            if (!$schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD) ||
                array_get($extraNew, 'is_virtual', false)
            ) {
                // no need to build what the db doesn't support, use extras and bail
                $extraNew['extra_type'] = $type;
            } else {
                if ($schema->isUndiscoverableType($type)) {
                    $extraNew['extra_type'] = $type;
                }

                $result = $this->buildTableField($internalTableName, $field);
                $commands = array_merge($commands, (array)array_get($result, 'commands'));
                $references = array_merge($references, (array)array_get($result, 'references'));
                $indexes = array_merge($indexes, (array)array_get($result, 'indexes'));

                $columns[$name] = $field;
            }

            if (!empty($extraNew)) {
                $extraNew['table'] = $table_name;
                $extraNew['field'] = $name;
                $extras[] = $extraNew;
            }
        }

        return [
            'columns'    => $columns,
            'references' => $references,
            'indexes'    => $indexes,
            'extras'     => $extras,
            'commands'   => $commands,
        ];
    }

    /**
     * @param TableSchema $table_schema
     * @param array       $fields
     * @param bool        $allow_update
     * @param bool        $allow_delete
     *
     * @throws \Exception
     * @return array
     */
    protected function buildTableFields(
        $table_schema,
        $fields,
        $allow_update = false,
        $allow_delete = false
    ) {
        if (!is_array($fields) || empty($fields)) {
            throw new \Exception('There are no fields in the requested schema.');
        }

        if (!isset($fields[0])) {
            // single record possibly passed in without wrapper array
            $fields = [$fields];
        }

        $schema = $this->parent->getSchema();
        $columns = [];
        $alterColumns = [];
        $dropColumns = [];
        $references = [];
        $indexes = [];
        $extras = [];
        $dropExtras = [];
        $commands = [];
        $internalTableName = $table_schema->internalName;
        foreach ($fields as $field) {
            $this->cleanClientField($field);
            $name = array_get($field, 'name');

            /** @type ColumnSchema $oldField */
            if ($oldField = $table_schema->getColumn($name)) {
                // UPDATE
                if (!$allow_update) {
                    throw new \Exception("Field '$name' already exists in table '{$table_schema->name}'.");
                }

                $oldArray = $oldField->toArray();
                $diffFields = array_diff($this->fieldExtras, ['picklist', 'validation', 'db_function']);
                $extraNew = array_diff_assoc(array_only($field, $diffFields), array_only($oldArray, $diffFields));

                if (array_key_exists('picklist', $field)) {
                    $picklist = (array)array_get($field, 'picklist');
                    $oldPicklist = (array)$oldField->picklist;
                    if ((count($picklist) !== count($oldPicklist)) ||
                        !empty(array_diff($picklist, $oldPicklist)) ||
                        !empty(array_diff($oldPicklist, $picklist))
                    ) {
                        $extraNew['picklist'] = $picklist;
                    }
                }

                if (array_key_exists('validation', $field)) {
                    $validation = (array)array_get($field, 'validation');
                    $oldValidation = (array)$oldField->validation;
                    if (json_encode($validation) !== json_encode($oldValidation)) {
                        $extraNew['validation'] = $validation;
                    }
                }

                if (array_key_exists('db_function', $field)) {
                    $dbFunction = (array)array_get($field, 'db_function');
                    $oldFunction = (array)$oldField->dbFunction;
                    if (json_encode($dbFunction) !== json_encode($oldFunction)) {
                        $extraNew['db_function'] = $dbFunction;
                    }
                }

                // clean out extras
                $noDiff = array_merge($this->fieldExtras, ['default', 'native']);
                $settingsNew = array_diff_assoc(array_except($field, $noDiff), array_except($oldArray, $noDiff));

                // may be an array due to expressions
                if (array_key_exists('default', $settingsNew)) {
                    $default = $settingsNew['default'];
                    if ($default !== $oldField->defaultValue) {
                        $settingsNew['default'] = $default;
                    }
                }
                if (array_key_exists('native', $settingsNew)) {
                    $native = $settingsNew['native'];
                    if ($native !== $oldField->native) {
                        $settingsNew['native'] = $native;
                    }
                }

                // if empty, nothing to do here, check extras
                if (empty($settingsNew)) {
                    if (!empty($extraNew)) {
                        $extraNew['table'] = $table_schema->name;
                        $extraNew['field'] = $name;
                        $extras[] = $extraNew;
                    }

                    continue;
                }

                $type = strtolower((string)array_get($field, 'type'));
                if (!$schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD) ||
                    array_get($extraNew, 'is_virtual', false) || $oldField->isVirtual
                ) {
                    if (!$oldField->isVirtual) {
                        throw new \Exception("Field '$name' already exists as non-virtual in table '{$table_schema->name}'.");
                    }
                    // no need to build what the db doesn't support, use extras and bail
                    $extraNew['extra_type'] = $type;
                } else {
                    if ($schema->isUndiscoverableType($type)) {
                        $extraNew['extra_type'] = $type;
                    }

                    $result = $this->buildTableField($internalTableName, $field, true, $oldField);
                    $commands = array_merge($commands, (array)array_get($result, 'commands'));
                    $references = array_merge($references, (array)array_get($result, 'references'));
                    $indexes = array_merge($indexes, (array)array_get($result, 'indexes'));

                    $alterColumns[$name] = $field;
                }
            } else {
                // CREATE

                // clean out extras
                $extraNew = array_only($field, $this->fieldExtras);
                $field = array_except($field, $this->fieldExtras);

                $type = strtolower((string)array_get($field, 'type'));
                if (!$schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD) ||
                    array_get($extraNew, 'is_virtual', false)
                ) {
                    // no need to build what the db doesn't support, use extras and bail
                    $extraNew['extra_type'] = $type;
                } else {
                    if ($schema->isUndiscoverableType($type)) {
                        $extraNew['extra_type'] = $type;
                    }

                    $result = $this->buildTableField($internalTableName, $field, true);
                    $commands = array_merge($commands, (array)array_get($result, 'commands'));
                    $references = array_merge($references, (array)array_get($result, 'references'));
                    $indexes = array_merge($indexes, (array)array_get($result, 'indexes'));

                    $columns[$name] = $field;
                }
            }

            if (!empty($extraNew)) {
                $extraNew['table'] = $table_schema->name;
                $extraNew['field'] = $name;
                $extras[] = $extraNew;
            }
        }

        if ($allow_delete) {
            // check for columns to drop
            /** @type  ColumnSchema $oldField */
            foreach ($table_schema->getColumns() as $oldField) {
                $found = false;
                foreach ($fields as $field) {
                    $field = array_change_key_case($field, CASE_LOWER);
                    if (array_get($field, 'name') === $oldField->name) {
                        $found = true;
                    }
                }
                if (!$found) {
                    if ($schema->supportsResourceType(DbResourceTypes::TYPE_TABLE_FIELD) && !$oldField->isVirtual) {
                        $dropColumns[] = $oldField->name;
                    }
                    $dropExtras[$table_schema->name][] = $oldField->name;
                }
            }
        }

        return [
            'columns'       => $columns,
            'alter_columns' => $alterColumns,
            'drop_columns'  => $dropColumns,
            'references'    => $references,
            'indexes'       => $indexes,
            'extras'        => $extras,
            'drop_extras'   => $dropExtras,
            'commands'      => $commands,
        ];
    }

    /**
     * @param string       $tableName
     * @param array        $field
     * @param bool         $oldTable
     * @param ColumnSchema $oldField
     *
     * @return array
     * @throws \Exception
     */
    protected function buildTableField($tableName, $field, $oldTable = false, $oldField = null)
    {
        $name = array_get($field, 'name');
        $type = strtolower((string)array_get($field, 'type'));
        $commands = [];
        $indexes = [];
        $references = [];
        $schema = $this->parent->getSchema();
        switch ($type) {
            case DbSimpleTypes::TYPE_ID:
                $pkExtras = $schema->getPrimaryKeyCommands($tableName, $name);
                $commands = array_merge($commands, $pkExtras);
                break;
        }

        if (((DbSimpleTypes::TYPE_REF == $type) || array_get($field, 'is_foreign_key'))) {
            // special case for references because the table referenced may not be created yet
            if (empty($refTable = array_get($field, 'ref_table'))) {
                throw new \Exception("Invalid schema detected - no table element for reference type of $name.");
            }

            if ((false === strpos($refTable, '.')) && !empty($namingSchema = $this->parent->getNamingSchema())) {
                $refTable = $namingSchema . '.' . $refTable;
            }
            $refColumns = array_get($field, 'ref_field', array_get($field, 'ref_fields'));
            $refOnDelete = array_get($field, 'ref_on_delete');
            $refOnUpdate = array_get($field, 'ref_on_update');

            if ($schema->allowsSeparateForeignConstraint()) {
                if (!isset($oldField) || !$oldField->isForeignKey) {
                    // will get to it later, $refTable may not be there
                    $keyName = $schema->makeConstraintName('fk', $tableName, $name);
                    $references[] = [
                        'name'      => $keyName,
                        'table'     => $tableName,
                        'column'    => $name,
                        'ref_table' => $refTable,
                        'ref_field' => $refColumns,
                        'delete'    => $refOnDelete,
                        'update'    => $refOnUpdate,
                    ];
                }
            }
        }

        // regardless of type
        if (array_get($field, 'is_unique')) {
            if ($schema->requiresCreateIndex(true, !$oldTable)) {
                // will get to it later, create after table built
                $keyName = $schema->makeConstraintName('undx', $tableName, $name);
                $indexes[] = [
                    'name'   => $keyName,
                    'table'  => $tableName,
                    'column' => $name,
                    'unique' => true,
                    'drop'   => isset($oldField),
                ];
            }
        } elseif (array_get($field, 'is_index')) {
            if ($schema->requiresCreateIndex($oldTable, !$oldTable)) {
                // will get to it later, create after table built
                $keyName = $schema->makeConstraintName('ndx', $tableName, $name);
                $indexes[] = [
                    'name'   => $keyName,
                    'table'  => $tableName,
                    'column' => $name,
                    'drop'   => isset($oldField),
                ];
            }
        }

        return ['field' => $field, 'commands' => $commands, 'references' => $references, 'indexes' => $indexes];
    }

    /**
     * @param string $table_name
     * @param array  $related
     *
     * @throws \Exception
     * @return array
     */
    public function createTableRelated($table_name, $related)
    {
        if (!is_array($related) || empty($related)) {
            return [];
        }

        if (!isset($related[0])) {
            // single record possibly passed in without wrapper array
            $related = [$related];
        }

        $extra = [];
        $virtual = [];
        foreach ($related as $relation) {
            $this->cleanClientRelation($relation);
            // clean out extras
            $extraNew = array_only($relation, $this->relatedExtras);
            if (!empty($extraNew['alias']) || !empty($extraNew['label']) || !empty($extraNew['description']) ||
                !empty($extraNew['always_fetch']) || !empty($extraNew['flatten']) ||
                !empty($extraNew['flatten_drop_prefix'])
            ) {
                if (!empty($si = array_get($relation, 'ref_service_id'))) {
                    if ($this->getServiceId() !== $si) {
                        $relation['ref_service'] = ServiceManager::getServiceNameById($si);
                    }
                }
                if (!empty($si = array_get($relation, 'junction_service_id'))) {
                    if (!empty($si) && ($this->getServiceId() !== $si)) {
                        $relation['junction_service'] = ServiceManager::getServiceNameById($si);
                    }
                }
                $extraNew['table'] = $table_name;
                $extraNew['relationship'] = RelationSchema::buildName($relation);
                $extra[] = $extraNew;
            }

            // only virtual
            if (boolval(array_get($relation, 'is_virtual'))) {
                $relation = array_except($relation, $this->relatedExtras);
                $relation['table'] = $table_name;
                $virtual[] = $relation;
            } else {
                // todo create foreign keys here eventually as well?
            }
        }

        return [
            'related_extras'    => $extra,
            'virtual_relations' => $virtual,
        ];
    }

    /**
     * @param TableSchema $table_schema
     * @param array       $related
     * @param bool        $allow_update
     * @param bool        $allow_delete
     *
     * @throws \Exception
     * @return array
     */
    public function buildTableRelated(
        $table_schema,
        $related,
        $allow_update = false,
        $allow_delete = false
    ) {
        if (!is_array($related) || empty($related)) {
            throw new \Exception('There are no related elements in the requested schema.');
        }

        if (!isset($related[0])) {
            // single record possibly passed in without wrapper array
            $related = [$related];
        }

        $extras = [];
        $dropExtras = [];
        $virtuals = [];
        $dropVirtuals = [];
        foreach ($related as $relation) {
            $this->cleanClientRelation($relation);
            $name = array_get($relation, 'name');

            /** @type RelationSchema $oldRelation */
            if (!empty($name) && ($oldRelation = $table_schema->getRelation($name))) {
                // UPDATE
                if (!$allow_update) {
                    throw new \Exception("Relation '$name' already exists in table '{$table_schema->name}'.");
                }

                $oldArray = $oldRelation->toArray();
                $extraNew = array_only($relation, $this->relatedExtras);
                $extraOld = array_only($oldArray, $this->relatedExtras);
                if (!empty($extraNew = array_diff_assoc($extraNew, $extraOld))) {
                    // if all empty, delete the extras entry, otherwise update
                    $combined = array_merge($extraOld, $extraNew);
                    if (!empty($combined['alias']) || !empty($combined['label']) || !empty($combined['description']) ||
                        !empty($combined['always_fetch']) || !empty($combined['flatten']) ||
                        !empty($combined['flatten_drop_prefix'])
                    ) {
                        $extraNew['table'] = $table_schema->name;
                        $extraNew['relationship'] = $name;
                        $extras[] = $extraNew;
                    } else {
                        $dropExtras[$table_schema->name][] = $oldRelation->name;
                    }
                }

                // only virtual
                if (boolval(array_get($relation, 'is_virtual'))) {
                    // clean out extras
                    $noDiff = array_merge($this->relatedExtras, ['native']);
                    $relation = array_except($relation, $noDiff);
                    if (!empty(array_diff_assoc($relation, array_except($oldArray, $noDiff)))) {
                        $relation['table'] = $table_schema->name;
                        $virtuals[] = $relation;
                    }
                }
            } else {
                // CREATE
                // clean out extras
                $extraNew = array_only($relation, $this->relatedExtras);
                if (!empty($extraNew['alias']) || !empty($extraNew['label']) || !empty($extraNew['description']) ||
                    !empty($extraNew['always_fetch']) || !empty($extraNew['flatten']) ||
                    !empty($extraNew['flatten_drop_prefix'])
                ) {
                    if (!empty($si = array_get($relation, 'ref_service_id'))) {
                        if ($this->getServiceId() !== $si) {
                            $relation['ref_service'] = ServiceManager::getServiceNameById($si);
                        }
                    }
                    if (!empty($si = array_get($relation, 'junction_service_id'))) {
                        if (!empty($si) && ($this->getServiceId() !== $si)) {
                            $relation['junction_service'] = ServiceManager::getServiceNameById($si);
                        }
                    }
                    $extraNew['table'] = $table_schema->name;
                    $extraNew['relationship'] = RelationSchema::buildName($relation);
                    $extras[] = $extraNew;
                }

                // only virtual
                if (boolval(array_get($relation, 'is_virtual'))) {
                    $relation = array_except($relation, $this->relatedExtras);
                    $relation['table'] = $table_schema->name;
                    $virtuals[] = $relation;
                } else {
                    // todo create foreign keys here eventually as well?
                }
            }
        }

        if ($allow_delete && isset($oldSchema)) {
            // check for relations to drop
            /** @type RelationSchema $oldField */
            foreach ($oldSchema->getRelations() as $oldRelation) {
                $found = false;
                foreach ($related as $relation) {
                    $relation = array_change_key_case($relation, CASE_LOWER);
                    if (array_get($relation, 'name') === $oldRelation->name) {
                        $found = true;
                    }
                }
                if (!$found) {
                    if ($oldRelation->isVirtual) {
                        $dropVirtuals[$table_schema->name][] = $oldRelation->toArray();
                    } else {
                        $dropExtras[$table_schema->name][] = $oldRelation->name;
                    }
                }
            }
        }

        return [
            'related_extras'         => $extras,
            'drop_related_extras'    => $dropExtras,
            'virtual_relations'      => $virtuals,
            'drop_virtual_relations' => $dropVirtuals,
        ];
    }

    /**
     * @param array $field
     * @throws \Exception
     */
    protected function cleanClientField(array &$field)
    {
        $field = array_change_key_case($field, CASE_LOWER);
        if (empty($name = array_get($field, 'name'))) {
            throw new \Exception("Invalid schema detected - no name element.");
        }
        if (!empty($label = array_get($field, 'label'))) {
            if ($label === camelize($name, '_', true)) {
                unset($field['label']); // no need to create an entry just for the same label
            }
        }

        $picklist = array_get($field, 'picklist');
        if (!empty($picklist) && !is_array($picklist)) {
            // accept comma delimited from client side
            $field['picklist'] = array_map('trim', explode(',', trim($picklist, ',')));
        }

        // make sure we have boolean values, not integers or strings
        $booleanFieldNames = [
            'allow_null',
            'fixed_length',
            'supports_multibyte',
            'auto_increment',
            'is_unique',
            'is_index',
            'is_primary_key',
            'is_foreign_key',
            'is_virtual',
            'is_aggregate',
        ];
        foreach ($booleanFieldNames as $name) {
            if (isset($field[$name])) {
                $field[$name] = boolval($field[$name]);
            }
        }

        // tighten up type info
        if (isset($field['type'])) {
            $type = strtolower((string)array_get($field, 'type'));
            switch ($type) {
                case 'pk':
                    $type = DbSimpleTypes::TYPE_ID;
                    break;
                case 'fk':
                    $type = DbSimpleTypes::TYPE_REF;
                    break;
                case 'virtual':
                    // upgrade old virtual field definitions
                    $field['is_virtual'] = true;
                    if (!empty($functionInfo = array_get($field, 'db_function'))) {
                        $type = array_get($functionInfo, 'type', DbSimpleTypes::TYPE_STRING);
                        if ($function = array_get($functionInfo, 'function')) {
                            $field['db_function'] = [
                                [
                                    'use'           => [DbFunctionUses::SELECT],
                                    'function'      => $function,
                                    'function_type' => FunctionTypes::DATABASE,
                                ]
                            ];
                        }
                        if ($aggregate = array_get($functionInfo, 'aggregate')) {
                            $field['is_aggregate'] = $aggregate;
                        }
                    }
                    break;
            }
            $field['type'] = $type;
        }
    }

    /**
     * @param array $relation
     * @throws \Exception
     */
    protected function cleanClientRelation(array &$relation)
    {
        $relation = array_change_key_case($relation, CASE_LOWER);
        // make sure we have boolean values, not integers or strings
        $booleanFieldNames = [
            'is_virtual',
            'always_fetch',
            'flatten',
            'flatten_drop_prefix',
        ];
        foreach ($booleanFieldNames as $name) {
            if (isset($relation[$name])) {
                $relation[$name] = boolval($relation[$name]);
            }
        }

        if (boolval(array_get($relation, 'is_virtual'))) {
            // tighten up type info
            if (isset($relation['type'])) {
                $type = strtolower((string)array_get($relation, 'type', ''));
                switch ($type) {
                    case RelationSchema::BELONGS_TO:
                    case RelationSchema::HAS_ONE:
                    case RelationSchema::HAS_MANY:
                    case RelationSchema::MANY_MANY:
                        $relation['type'] = $type;
                        break;
                    default:
                        throw new \Exception("Invalid schema detected - invalid or missing type element.");
                        break;
                }
            }
        } else {
            if (empty(array_get($relation, 'name'))) {
                throw new \Exception("Invalid schema detected - no name element.");
            }
        }
    }

    /**
     * @param bool $refresh
     * @return array
     */
    public function getGraphQLSchema($refresh = false)
    {
        $service = $this->getServiceName();

        $tName = 'db_schema_table';
        $types = [
            'db_schema_table_relation' => new BaseType(RelationSchema::getSchema()),
            'db_schema_table_field'    => new BaseType(ColumnSchema::getSchema()),
            'db_schema_table'          => new BaseType(TableSchema::getSchema()),
        ];

        $queries = [];
        $qName = $this->formOperationName(Verbs::GET);
        $queries[$qName] = new ServiceSingleResourceQuery([
            'name'     => $qName,
            'type'     => $tName,
            'service'  => $service,
            'resource' => '_schema',
            'args'     => [
                'name'    => ['name' => 'name', 'type' => Type::STRING.'!'],
                'refresh' => ['name' => 'refresh', 'type' => Type::BOOLEAN],
            ],
        ]);
        $qName = $this->formOperationName(Verbs::GET, null, true);
        $queries[$qName] = new ServiceMultiResourceQuery([
            'name'     => $qName,
            'type'     => $tName,
            'service'  => $service,
            'resource' => '_schema',
            'args'     => [
                'ids'     => ['name' => 'ids', 'type' => '['.Type::STRING.']'],
                'schema'  => ['name' => 'schema', 'type' => Type::STRING],
                'refresh' => ['name' => 'refresh', 'type' => Type::BOOLEAN],
            ],
        ]);
        $qName = $qName . 'Names';
        $queries[$qName] = new ServiceResourceListQuery([
            'name'     => $qName,
            'service'  => $service,
            'resource' => '_schema',
            'args'     => [
                'schema'  => ['name' => 'schema', 'type' => Type::STRING],
                'refresh' => ['name' => 'refresh', 'type' => Type::BOOLEAN],
            ],
        ]);
        $mutations = [];

        return ['query' => $queries, 'mutation' => $mutations, 'types' => $types];
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
                        '200' => ['$ref' => '#/components/responses/TableSchemas']
                    ],
                ],
                'post'  => [
                    'summary'     => 'Create one or more tables.',
                    'description' => 'Post data should be a single table definition or an array of table definitions.',
                    'operationId' => 'create' . $capitalized . 'Tables',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/TableSchemas']
                    ],
                ],
                'put'   => [
                    'summary'     => 'Update (replace) one or more tables.',
                    'description' => 'Post data should be a single table definition or an array of table definitions.',
                    'operationId' => 'replace' . $capitalized . 'Tables',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/TableSchemas']
                    ],
                ],
                'patch' => [
                    'summary'     => 'Update (patch) one or more tables.',
                    'description' => 'Post data should be a single table definition or an array of table definitions.',
                    'operationId' => 'update' . $capitalized . 'Tables',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/TableSchemas']
                    ],
                ],
            ],
            $path . '/{table_name}'                              => [
                'parameters' => [
                    [
                        'name'        => 'table_name',
                        'description' => 'Name of the table to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve table definition for the given table.',
                    'description' => 'This describes the table, its fields and relations to other tables.',
                    'operationId' => 'describe' . $capitalized . 'Table',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/TableSchema']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Create a table with the given properties and fields.',
                    'description' => 'Post data should be an array of field properties.',
                    'operationId' => 'create' . $capitalized . 'Table',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update (replace) a table with the given properties.',
                    'description' => 'Post data should be an array of field properties.',
                    'operationId' => 'replace' . $capitalized . 'Table',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'patch'      => [
                    'summary'     => 'Update (patch) a table with the given properties.',
                    'description' => 'Post data should be an array of field properties.',
                    'operationId' => 'update' . $capitalized . 'Table',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/TableSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete (aka drop) the given table.',
                    'description' => 'Careful, this drops the database table and all of its contents.',
                    'operationId' => 'delete' . $capitalized . 'Table',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{table_name}/_field'                       => [
                'parameters' => [
                    [
                        'name'        => 'table_name',
                        'description' => 'Name of the table to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve table field definitions for the given table.',
                    'description' => 'This describes the table\'s fields.',
                    'operationId' => 'describe' . $capitalized . 'Fields',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/FieldSchemas']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Create table fields.',
                    'description' => 'Post data should be an array of fields and their properties.',
                    'operationId' => 'create' . $capitalized . 'Fields',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/FieldSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update (replace) table fields with the given properties.',
                    'description' => 'Post data should be an array of fields and their properties.',
                    'operationId' => 'replace' . $capitalized . 'Fields',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/FieldSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'patch'      => [
                    'summary'     => 'Update (patch) table fields with the given properties.',
                    'description' => 'Post data should be an array of field properties.',
                    'operationId' => 'update' . $capitalized . 'Fields',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/FieldSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete (aka drop) the given fields.',
                    'description' => 'Careful, this drops the table column and all of its contents.',
                    'operationId' => 'delete' . $capitalized . 'Fields',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{table_name}/_related'                     => [
                'parameters' => [
                    [
                        'name'        => 'table_name',
                        'description' => 'Name of the table to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve relationships definition for the given table.',
                    'description' => 'This describes the table relationships to other tables.',
                    'operationId' => 'describe' . $capitalized . 'Relationships',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/RelationshipSchemas']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Create table relationships with the given properties.',
                    'description' => 'Post data should be an array of relationship properties.',
                    'operationId' => 'create' . $capitalized . 'Relationships',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/RelationshipSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update (replace) table relationships with the given properties.',
                    'description' => 'Post data should be an array of relationship properties.',
                    'operationId' => 'replace' . $capitalized . 'Relationships',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/RelationshipSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'patch'      => [
                    'summary'     => 'Update (patch) a table with the given properties.',
                    'description' => 'Post data should be an array of relationship properties.',
                    'operationId' => 'update' . $capitalized . 'Relationships',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/RelationshipSchemas'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete the given table relationships.',
                    'description' => 'Removes the relationships between tables.',
                    'operationId' => 'delete' . $capitalized . 'Relationships',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{table_name}/_field/{field_name}'          => [
                'parameters' => [
                    [
                        'name'        => 'table_name',
                        'description' => 'Name of the table to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                    [
                        'name'        => 'field_name',
                        'description' => 'Name of the field to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve the definition of the given field for the given table.',
                    'description' => 'This describes the field and its properties.',
                    'operationId' => 'describe' . $capitalized . 'Field',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/FieldSchema']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update one field by identifier.',
                    'description' => 'Post data should be an array of field properties for the given field.',
                    'operationId' => 'replace' . $capitalized . 'Field',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/FieldSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'patch'      => [
                    'summary'     => 'Update one field by identifier.',
                    'description' => 'Post data should be an array of field properties for the given field.',
                    'operationId' => 'update' . $capitalized . 'Field',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/FieldSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Remove the given field from the given table.',
                    'description' => 'Careful, this drops the database table field/column and all of its contents.',
                    'operationId' => 'delete' . $capitalized . 'Field',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{table_name}/_related/{relationship_name}' => [
                'parameters' => [
                    [
                        'name'        => 'table_name',
                        'description' => 'Name of the table to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                    [
                        'name'        => 'relationship_name',
                        'description' => 'Name of the relationship to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve the definition of the given relationship for the given table.',
                    'description' => 'This describes the relationship and its properties.',
                    'operationId' => 'describe' . $capitalized . 'Relationship',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/RelationshipSchema']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update one relationship by identifier.',
                    'description' => 'Post data should be an array of properties for the given relationship.',
                    'operationId' => 'replace' . $capitalized . 'Relationship',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/RelationshipSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'patch'      => [
                    'summary'     => 'Update one relationship by identifier.',
                    'description' => 'Post data should be an array of properties for the given relationship.',
                    'operationId' => 'update' . $capitalized . 'Relationship',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/RelationshipSchema'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Remove the given relationship from the given table.',
                    'description' => 'Removes the relationship between the tables given.',
                    'operationId' => 'delete' . $capitalized . 'Relationship',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
        ];

        return $paths;
    }
}