<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturnParcel extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['submitted_at' => 'immutable_datetime'];
}
