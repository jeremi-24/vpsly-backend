<?php

namespace App\Models;

use App\Traits\HasQuotas;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory, HasQuotas;

    protected $fillable = [
        'name',
        'owner_id',
        'plan',
        'subscription_status',
        'last_payment_at',
        'expires_at',
        'payment_reference',
    ];

    protected $appends = ['applications_count', 'servers_count', 'databases_count'];

    public function getApplicationsCountAttribute()
    {
        return $this->applications()->count();
    }

    public function getServersCountAttribute()
    {
        return $this->servers()->count();
    }

    public function getDatabasesCountAttribute()
    {
        return $this->databases()->count();
    }

    public function databases()
    {
        return $this->hasMany(StandaloneDatabase::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
