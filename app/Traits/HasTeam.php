<?php

namespace App\Traits;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait HasTeam
{
    protected static function bootHasTeam()
    {
        // 1. Global Scope : Filtrage automatique par l'équipe active de l'utilisateur
        static::addGlobalScope('team', function (Builder $builder) {
            if (Auth::check() && Auth::user()->current_team_id) {
                $builder->where('team_id', Auth::user()->current_team_id);
            }
        });

        // 2. Auto-attribution : Assigne automatiquement la team_id lors de la création
        static::creating(function ($model) {
            if (empty($model->team_id) && Auth::check() && Auth::user()->current_team_id) {
                $model->team_id = Auth::user()->current_team_id;
            }
        });
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
