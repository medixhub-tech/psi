<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class IntegrationCredentials
{
    public static function get(string $provider): array
    {
        if (! in_array($provider, ['evolution', 'google'], true)) {
            return config('integrations.'.$provider, []);
        }
        $stored = DB::table('integration_settings')->where('id', 1)->value($provider.'_credentials');

        return $stored ? json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR) : config('integrations.'.$provider, []);
    }

    public static function googleFingerprint(): string
    {
        return hash('sha256', json_encode(self::get('google'), JSON_THROW_ON_ERROR));
    }
}
