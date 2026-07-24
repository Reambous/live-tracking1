<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Train extends Model
{
    protected $fillable = [
        'train_code',
        'name',
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class)->orderBy('stop_order');
    }
}
