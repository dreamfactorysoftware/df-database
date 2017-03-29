<?php
namespace DreamFactory\Core\Database\Models;

use DreamFactory\Core\Models\BaseServiceConfigNoDbModel;

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

        return parent::toStorageFormat($config, $old_config);
    }
}