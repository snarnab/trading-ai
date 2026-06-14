<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertTimeframe extends Model
{
    protected $fillable = [
        'name',
        'is_enabled',
    ];
}