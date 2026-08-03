<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'is_sensitive',
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }

    /**
     * Decrypted value. Sensitive settings are stored encrypted-at-rest.
     */
    public function getDecryptedValue(): ?string
    {
        if ($this->value === null) {
            return null;
        }

        if (! $this->is_sensitive) {
            return $this->value;
        }

        try {
            return Crypt::decryptString($this->value);
        } catch (\Throwable) {
            // Value was stored in plaintext before being marked sensitive, or
            // the app key changed. Return raw rather than crashing the app.
            return $this->value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = $setting->getDecryptedValue();

        return $value === null ? $default : $value;
    }

    public static function put(string $key, ?string $value, bool $sensitive = false): self
    {
        $stored = ($sensitive && $value !== null) ? Crypt::encryptString($value) : $value;

        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_sensitive' => $sensitive],
        );
    }
}
