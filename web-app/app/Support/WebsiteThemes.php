<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * The public website designs the owner can choose from in Settings. Every
 * theme shows the same data; only the look of the shared header, footer and
 * home page changes. To add a theme: add its key here, a lang entry under
 * settings.website_theme_*, and its layout and home page under
 * resources/js/themes.
 */
final class WebsiteThemes
{
    public const DEFAULT = 'default';

    /** @var list<string> */
    public const ALL = ['default', 'bistro'];

    public static function current(): string
    {
        $theme = Setting::current()->website_theme;

        return in_array($theme, self::ALL, true) ? $theme : self::DEFAULT;
    }
}
