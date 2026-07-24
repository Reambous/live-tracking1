<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $fillable = [
        'station_code',
        'name',
        'latitude',
        'longitude',
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
