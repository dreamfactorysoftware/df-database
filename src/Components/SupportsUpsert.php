<?php
namespace DreamFactory\Core\Database\Components;


trait SupportsUpsert
{
    /**
     * {@inheritdoc}
     */
    public static function getConfig($id, $local_config = null, $protect = true)
    {
        $config = parent::getConfig($id, $local_config, $protect);

        $config['allow_upsert'] = filter_var(array_get($local_config, 'allow_upsert'), FILTER_VALIDATE_BOOLEAN);

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config, $local_config = null)
    {
        $result = (array)parent::setConfig($id, $config, $local_config);

        if (isset($config['allow_upsert'])) {
            $result['allow_upsert'] = filter_var(array_get($config, 'allow_upsert'), FILTER_VALIDATE_BOOLEAN);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigSchema()
    {
        $schema = (array)parent::getConfigSchema();
        $schema[] = [
            'name'        => 'allow_upsert',
            'label'       => 'Allow Upsert',
            'type'        => 'boolean',
            'allow_null'  => false,
            'default'     => false,
            'description' => 'Allow PUT to create records if they do not exist and the service is capable.',
        ];

        return $schema;
    }
}