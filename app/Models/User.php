<?php

namespace App\Models;

use App\Services\RolePermissions;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'position',
        'email',
        'password',
        'role',
        'cpu_discipline',
        'region',
        'region_scope',
        'province',
        'municipality',
        'active',
        'google_id',
        'account_status',
        'requested_role',
        'registration_note',
        'reviewed_at',
        'reviewed_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_email_code',
        'google_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'region_scope' => 'array',
            'two_factor_email_code_expires_at' => 'datetime',
        ];
    }

    public function canViewSensitiveCaseData(): bool
    {
        return app(RolePermissions::class)->allows($this, 'cases.view');
    }

    public function hasPermission(string $permission): bool
    {
        return app(RolePermissions::class)->allows($this, $permission);
    }
}
