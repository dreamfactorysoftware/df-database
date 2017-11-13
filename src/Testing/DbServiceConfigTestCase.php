<?php

namespace DreamFactory\Core\Database\Testing;

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Testing\TestCase;
use DreamFactory\Core\Models\Service;
use ServiceManager;
use Config;

class DbServiceConfigTestCase extends TestCase
{
    const RESOURCE = 'service';

    protected $serviceId = 'system';

    protected $types = [];

    public function getDbServiceConfig($name, $type, $maxRecords = null)
    {
        $config = [
            'name'      => $name,
            'label'     => 'test db service',
            'type'      => $type,
            'is_active' => true,
            'config'    => [
                'host'     => 'localhost',
                'database' => 'my-db',
                'username' => 'user',
                'password' => 'secret'
            ]
        ];

        if (!empty($maxRecords)) {
            $config['config']['max_records'] = $maxRecords;
        }

        return $config;
    }

    public function tearDown()
    {
        foreach ($this->types as $type) {
            Service::whereName($type . '-db')->delete();
        }

        parent::tearDown();
    }

    public function testMaxRecordsUnset()
    {
        foreach ($this->types as $type) {
            $config = $this->getDbServiceConfig($type . '-db', $type);
            $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, ['fields' => 'config'], ['resource' => [$config]]);
            $this->assertEquals(201, $rs->getStatusCode());
            /** @var \DreamFactory\Core\Database\Services\BaseDbService $service */
            $service = ServiceManager::getService($type . '-db');
            $this->assertEquals(1000, $service->getMaxRecordsLimit());
            $this->assertEquals(3000, $service->getMaxRecordsLimit(3000));

            $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/' . $service->getServiceId());
            $this->assertEmpty(($rs->getContent())['config']['max_records']);
        }
    }

    public function testMaxRecordsNegative()
    {
        foreach ($this->types as $type) {
            $config = $this->getDbServiceConfig($type . '-db', $type, -1);
            $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], ['resource' => [$config]]);
            $this->assertEquals(201, $rs->getStatusCode());
            /** @var \DreamFactory\Core\Database\Services\BaseDbService $service */
            $service = ServiceManager::getService($type . '-db');
            $this->assertEquals(1000, $service->getMaxRecordsLimit());
            $this->assertEquals(3000, $service->getMaxRecordsLimit(3000));

            $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/' . $service->getServiceId());
            $this->assertEmpty(($rs->getContent())['config']['max_records']);
        }
    }

    public function testMaxRecordsZero()
    {
        foreach ($this->types as $type) {
            $config = $this->getDbServiceConfig($type . '-db', $type, 0);
            $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], ['resource' => [$config]]);
            $this->assertEquals(201, $rs->getStatusCode());
            /** @var \DreamFactory\Core\Database\Services\BaseDbService $service */
            $service = ServiceManager::getService($type . '-db');
            $this->assertEquals(1000, $service->getMaxRecordsLimit());
            $this->assertEquals(3000, $service->getMaxRecordsLimit(3000));

            $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/' . $service->getServiceId());
            $this->assertEmpty(($rs->getContent())['config']['max_records']);
        }
    }

    public function testMaxRecordsValid()
    {
        foreach ($this->types as $type) {
            $config = $this->getDbServiceConfig($type . '-db', $type, 10);
            $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], ['resource' => [$config]]);
            $this->assertEquals(201, $rs->getStatusCode());
            /** @var \DreamFactory\Core\Database\Services\BaseDbService $service */
            $service = ServiceManager::getService($type . '-db');
            $this->assertEquals(10, $service->getMaxRecordsLimit());
            $this->assertEquals(10, $service->getMaxRecordsLimit(3000));

            $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/' . $service->getServiceId());
            $this->assertEquals(10, ($rs->getContent())['config']['max_records']);
        }
    }

    public function testMaxRecordsEnvCap()
    {
        foreach ($this->types as $type) {
            $config = $this->getDbServiceConfig($type . '-db', $type, 100100);
            $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], ['resource' => [$config]]);
            $this->assertEquals(201, $rs->getStatusCode());
            /** @var \DreamFactory\Core\Database\Services\BaseDbService $service */
            $service = ServiceManager::getService($type . '-db');
            $this->assertEquals(100000, $service->getMaxRecordsLimit());
            $this->assertEquals(100000, $service->getMaxRecordsLimit(3000));

            Config::set('database.max_records_returned', 100050);

            $this->assertEquals(100050, $service->getMaxRecordsLimit());
            $this->assertEquals(100050, $service->getMaxRecordsLimit(3000));

            Config::set('database.max_records_returned', 100000);

            $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/' . $service->getServiceId());
            $this->assertEquals(100100, ($rs->getContent())['config']['max_records']);
        }
    }
}