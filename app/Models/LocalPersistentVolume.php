<?php

namespace App\Models;

use App\Traits\HasTeam;
use Illuminate\Database\Eloquent\Model;

class LocalPersistentVolume extends Model
{
    use HasTeam;

    protected $fillable = [
        'name',
        'mount_path',
        'host_path',
        'resource_id',
        'resource_type',
        'team_id',
    ];

    public function resource()
    {
        return $this->morphTo();
    }
}
