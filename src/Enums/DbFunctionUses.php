<?php
namespace DreamFactory\Core\Database\Enums;

use DreamFactory\Core\Enums\FactoryEnum;

/**
 * DbFunctionUses
 * DB operations or parts of operations where DB functions can be used
 */
class DbFunctionUses extends FactoryEnum
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const SELECT = 'SELECT';
    const FILTER = 'FILTER';
    const INSERT = 'INSERT';
    const UPDATE = 'UPDATE';
}
