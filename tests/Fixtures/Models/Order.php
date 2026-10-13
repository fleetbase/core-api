<?php

namespace Fleetbase\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for an order-like comment subject (Fleet-Ops `Order`); comments resolve their default
 * visibility from the subject's class basename.
 */
class Order extends Model
{
    protected $table      = 'orders';
    protected $primaryKey = 'uuid';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
}
