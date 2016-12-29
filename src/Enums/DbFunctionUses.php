<?php
namespace DreamFactory\Core\Database\Enums;

use DreamFactory\Library\Utility\Enums\FactoryEnum;

/**
 * DbFunctionUses
 * DB operations or parts of operations where DB functions can be used
 */
class DbFunctionUses extends FactoryEnum
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const SELECT = 'select';
    const FILTER = 'filter';
    const INSERT = 'insert';
    const UPDATE = 'update';
}
