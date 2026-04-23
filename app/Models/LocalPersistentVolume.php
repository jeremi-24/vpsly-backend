<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalPersistentVolume extends Model
{
    protected $fillable = [
        'name',
        'mount_path',
        'host_path',
        'resource_id',
        'resource_type',
    ];

    public function resource()
    {
        return $this->morphTo();
    }
}
