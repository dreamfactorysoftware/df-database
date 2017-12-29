<?php

namespace DreamFactory\Core\Database\Models;

use DreamFactory\Core\Database\Resources\BaseDbTableResource;
use DreamFactory\Core\Models\BaseServiceConfigNoDbModel;
use DreamFactory\Core\Exceptions\BadRequestException;

/**
 * BaseSqlDbConfig
 *
 * @property boolean $allow_update
 * @method static BaseDbConfig whereServiceId($value)
 */
class BaseDbConfig extends BaseServiceConfigNoDbModel
{
    /**
     * {@inheritdoc}
     */
    public static function getSchema()
    {
        return [
            'allow_upsert' => [
                'name'        => 'allow_upsert',
                'label'       => 'Allow Upsert',
                'type'        => 'boolean',
                'allow_null'  => false,
                'default'     => false,
                'description' => 'Allow PUT to create records if they do not exist and the service is capable.',
            ],
            'max_records'  => [
                'name'        => 'max_records',
                'label'       => 'Maximum Records',
                'type'        => 'integer',
                'allow_null'  => false,
                'default'     => 1000,
                'description' => 'Maximum number of records returned by this service. Must be a number greater than 0. Default is 1000.',
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function fromStorageFormat($config, $protect = true)
    {
        $allowUpsert = array_get($config, 'allow_upsert');
        if (!is_bool($allowUpsert)) {
            $allowUpsert = filter_var($allowUpsert, FILTER_VALIDATE_BOOLEAN);
        }
        $config['allow_upsert'] = $allowUpsert;
        $config['max_records'] = filter_var(array_get($config, 'max_records'), FILTER_VALIDATE_INT);

        return parent::fromStorageFormat($config, $protect);
    }

    /**
     * {@inheritdoc}
     */
    public static function toStorageFormat(&$config, $old_config = null)
    {
        if (isset($config['allow_upsert'])) {
            $allowUpsert = array_get($config, 'allow_upsert');
            if (!is_bool($allowUpsert)) {
                $config['allow_upsert'] = filter_var($allowUpsert, FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($config['max_records'])) {
            if (!is_int($config['max_records'])) {
                $config['max_records'] = filter_var($config['max_records'], FILTER_VALIDATE_INT);
            }
        }
        if (array_get($config, 'max_records', 0) <= 0 || empty($config['max_records'])) {
            unset($config['max_records']);
        }

        return parent::toStorageFormat($config, $old_config);
    }
}