<?php

namespace App\Support;

use App\Models\Setting;

class Robots
{
    public static function default(): string
    {
        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /cp',
            'Disallow: /api/cp/',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /order-success',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ])."\n";
    }

    /** Text saved in /cp → Settings, or the default when empty. */
    public static function content(): string
    {
        $saved = trim((string) Setting::where('key', 'robots_txt')->value('value'));

        return $saved === '' ? self::default() : str_replace("\r\n", "\n", $saved)."\n";
    }
}
