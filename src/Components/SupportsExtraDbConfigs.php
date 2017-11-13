<?php

namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Models\ServiceCacheConfig;

trait SupportsExtraDbConfigs
{
    /**
     * {@inheritdoc}
     */
    public static function getConfig($id, $local_config = null, $protect = true)
    {
        $config = parent::getConfig($id, $local_config, $protect);
        if ($cacheConfig = ServiceCacheConfig::whereServiceId($id)->first()) {
            $config = array_merge($config, $cacheConfig->toArray());
        }

        $config['allow_upsert'] = filter_var(array_get($local_config, 'allow_upsert'), FILTER_VALIDATE_BOOLEAN);
        $config['max_records'] = filter_var(array_get($local_config, 'max_records'), FILTER_VALIDATE_INT);

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config, $local_config = null)
    {
        ServiceCacheConfig::setConfig($id, $config, $local_config);

        $result = (array)parent::setConfig($id, $config, $local_config);

        if (isset($config['allow_upsert'])) {
            $result['allow_upsert'] = filter_var($config['allow_upsert'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($config['max_records'])) {
            $result['max_records'] = filter_var($config['max_records'], FILTER_VALIDATE_INT);
        }
        if (array_get($result, 'max_records', 0) <= 0 || empty($config['max_records'])) {
            unset($result['max_records']);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public static function storeConfig($id, $config)
    {
        ServiceCacheConfig::storeConfig($id, $config);

        return parent::storeConfig($id, $config);
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigSchema()
    {
        $schema = (array)parent::getConfigSchema();
        $schema = array_merge($schema, static::getExtraConfigSchema(), ServiceCacheConfig::getConfigSchema());

        return $schema;
    }

    public static function getExtraConfigSchema()
    {
        return [
            [
                'name'        => 'allow_upsert',
                'label'       => 'Allow Upsert',
                'type'        => 'boolean',
                'allow_null'  => false,
                'default'     => false,
                'description' => 'Allow PUT to create records if they do not exist and the service is capable.',
            ],
            [
                'name'        => 'max_records',
                'label'       => 'Maximum Records',
                'type'        => 'integer',
                'allow_null'  => false,
                'default'     => 1000,
                'description' => 'Maximum number of records returned by this service. Must be a number greater than 0. Default is 1000.',
            ],
        ];
    }
}