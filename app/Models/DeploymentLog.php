<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentLog extends Model
{
    protected $guarded = [];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
