<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $usuario): void {
            $usuario->api_key ??= static::generarApiKey();
        });
    }

    public static function generarApiKey(): string
    {
        do {
            $apiKey = Str::random(40);
        } while (static::where('api_key', $apiKey)->exists());

        return $apiKey;
    }

    public function asegurarApiKey(): string
    {
        if ($this->api_key) {
            return $this->api_key;
        }

        $this->forceFill(['api_key' => static::generarApiKey()])->save();

        return $this->api_key;
    }

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
        ];
    }

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }
}
