<?php

namespace App\Models;

use App\Traits\HasTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentLog extends Model
{
    use HasTeam;
    protected $guarded = [];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
