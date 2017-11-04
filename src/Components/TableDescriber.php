<?php
namespace DreamFactory\Core\Database\Components;

use DreamFactory\Core\Utility\ResourcesWrapper;

trait TableDescriber
{
    public static function getApiDocCommonModels()
    {
        $wrapper = ResourcesWrapper::getWrapper();

        return [
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
    }
}