<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeamInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'email',
        'token',
        'role',
        'expires_at',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
