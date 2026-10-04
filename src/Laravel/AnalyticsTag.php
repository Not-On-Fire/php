<?php

namespace NotOnFire\Laravel;

use NotOnFire\NotOnFire;

/**
 * The analytics tag for this application, or an empty string when there is
 * no analytics ID or nothing may be sent from this environment. Behind both
 * the injecting middleware and the @notonfireAnalytics directive.
 */
final class AnalyticsTag
{
    public static function render(): string
    {
        $id = Settings::analyticsId();

        if ($id === null || ! Settings::active()) {
            return '';
        }

        return NotOnFire::analyticsTag($id, Settings::analyticsDomains(), Settings::analyticsUrl());
    }
}
