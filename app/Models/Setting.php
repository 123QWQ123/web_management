<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    // Helper to fetch a simple string value stored in the json `value` field.
    // If the stored value is an array like ["1.2.3.4"], this will return the first element.
    public static function get(string $key, $default = null)
    {
        $record = static::where('key', $key)->first();

        if (! $record) {
            return $default;
        }

        $v = $record->value;

        if (is_array($v)) {
            // common case: value stored as ["ip"] or as associative array
            if (isset($v[0]) && is_scalar($v[0])) {
                return $v[0];
            }

            // fallback: encode array as json string
            return json_encode($v);
        }

        return $v;
    }
}
