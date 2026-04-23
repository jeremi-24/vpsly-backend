<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Génère un UUID si absent (compatible avec l'approche Coolify)
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Helper pour nettoyer les noms (utile pour Docker)
     */
    public function getSanitizedNameAttribute()
    {
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', $this->name));
    }
}
