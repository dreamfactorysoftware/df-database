<?php

namespace DreamFactory\Core\Database\Resources;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\GraphQL\Contracts\GraphQLHandlerInterface;
use DreamFactory\Core\Resources\BaseRestResource;

class DbAdmin extends BaseDbResource implements GraphQLHandlerInterface
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with database schema
     */
    const RESOURCE_NAME = 'admin';
    /**
     * Replacement tag for dealing with table schema events
     */
    const EVENT_IDENTIFIER = '{table_name}';

    //*************************************************************************
    //	Members
    //*************************************************************************

    public function getResources()
    {
        return array_values($this->getResourceHandlers());
    }

    protected function getResourceHandlers()
    {
        return [
            DbAdminSchema::RESOURCE_NAME    => [
                'name'       => DbAdminSchema::RESOURCE_NAME,
                'class_name' => DbAdminSchema::class,
                'label'      => 'Admin Schema',
            ],
            DbAdminTable::RESOURCE_NAME => [
                'name'       => DbAdminTable::RESOURCE_NAME,
                'class_name' => DbAdminTable::class,
                'label'      => 'Admin Table',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function handleResource(array $resources)
    {
        try {
            return parent::handleResource($resources);
        } catch (NotFoundException $e) {
            if (is_numeric($this->resource)) {
                //  Perform any pre-request processing
                $this->preProcess();

                $this->response = $this->processRequest();

                if (false !== $this->response) {
                    //  Perform any post-request processing
                    $this->postProcess();
                }
                //	Inherent failure?
                if (false === $this->response) {
                    $what =
                        (!empty($this->resourcePath) ? " for resource '{$this->resourcePath}'" : ' without a resource');
                    $message =
                        ucfirst($this->action) .
                        " requests $what are not currently supported by the '{$this->name}' service.";

                    throw new BadRequestException($message);
                }

                //  Perform any response processing
                return $this->respond();
            } else {
                throw $e;
            }
        }
    }

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
     * @param bool $refresh
     * @return array
     * @throws InternalServerErrorException
     */
    public function getGraphQLSchema($refresh = false)
    {
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
                $content = $resource->getGraphQLSchema();
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
    }
}