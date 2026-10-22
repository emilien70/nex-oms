<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturnEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['observed_at' => 'immutable_datetime'];
}
