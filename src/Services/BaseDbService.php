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
        $this->cacheEnabled = array_get_bool($this->config, 'cache_enabled');
        $this->cacheTTL = intval(array_get($this->config, 'cache_ttl', \Config::get('df.default_cache_ttl')));
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
            $this->schema->setServiceId($this->getServiceId());
        }

        return $this->schema;
    }

    public function getSchemas($refresh = false)
    {
        if ($refresh || (is_null($result = $this->getFromCache('schemas')))) {
            /** @type string[] $result */
            $result = $this->getSchema()->getResourceNames(DbResourceTypes::TYPE_SCHEMA);
            $this->addToCache('schemas', $result, true);
        }

        return $result;
    }
}