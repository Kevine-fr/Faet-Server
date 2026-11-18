<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Models\Customer;
use App\Models\Admin;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'street',
        'postal_code',
        'city',
        'country',
        'profile_picture',
        'email',
        'password',
        'provider',
        'provider_id',
        'google_id',
        'status',
        'email_verified_at',
        'password_expires_at',
        'session_timeout',
        'max_login_attempts',
        'email_verification_code',
        'email_verification_expires_at',
        'newsletter_enabled',
        'notification_enabled'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'status' => 'string',
        'email_verified_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function vendor()
    {
        return $this->hasOne(Vendor::class, 'user_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class);
    }

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function vendorSubscriptions()
    {
        return $this->hasMany(VendorSubscription::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }
}