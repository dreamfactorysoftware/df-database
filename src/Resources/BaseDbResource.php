<?php

namespace DreamFactory\Core\Database\Resources;

use DreamFactory\Core\Database\Components\DbSchemaExtras;
use DreamFactory\Core\Contracts\RequestHandlerInterface;
use DreamFactory\Core\Contracts\SchemaInterface;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Database\Services\BaseDbService;
use Illuminate\Database\ConnectionInterface;

abstract class BaseDbResource extends BaseRestResource
{
    use DbSchemaExtras;

    const RESOURCE_IDENTIFIER = 'name';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var integer Service identifier
     */
    protected $serviceId = null;
    /**
     * @var BaseDbService
     */
    protected $parent = null;
    /**
     * @var ConnectionInterface
     */
    protected $dbConn = null;
    /**
     * @var SchemaInterface
     */
    protected $schema = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@inheritdoc}
     */
    protected static function getResourceIdentifier()
    {
        return static::RESOURCE_IDENTIFIER;
    }

    /**
     * @param RequestHandlerInterface $parent
     */
    public function setParent(RequestHandlerInterface $parent)
    {
        parent::setParent($parent);

        /** @var BaseDbService $parent */
        $this->serviceId = $parent->getServiceId();
    }

    /**
     * @return string
     */
    abstract public function getResourceName();

    /**
     * @param null $schema
     * @param bool $refresh
     *
     * @return array
     */
    abstract public function listResources(
        /** @noinspection PhpUnusedParameterInspection */
        $schema = null,
        $refresh = false
    );

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
            $output[] = $this->getResourceName() . '/' . $name;
        }

        return $output;
    }

    protected function getApiDocPaths()
    {
        $results = parent::getApiDocPaths();
        $resourceName = strtolower($this->name);
        $path = '/' . $resourceName;

        $results[$path]['get']['parameters'] = [
            ApiOptions::documentOption(ApiOptions::AS_LIST),
            ApiOptions::documentOption(ApiOptions::FIELDS),
            ApiOptions::documentOption(ApiOptions::IDS),
            ApiOptions::documentOption(ApiOptions::REFRESH),
        ];

        return $results;
    }
}