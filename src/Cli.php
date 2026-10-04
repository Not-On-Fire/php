<?php

namespace NotOnFire;

/**
 * `vendor/bin/notonfire-test` for plain PHP applications. It reads the same
 * NOTONFIRE_* variables NotOnFire::init() and analyticsScript() fall back
 * to, so it can be run with the values in front of it:
 *
 *     NOTONFIRE_DSN='https://…' vendor/bin/notonfire-test
 */
final class Cli
{
    /**
     * @param  list<string>  $arguments
     * @param  resource  $output
     */
    public static function run(array $arguments, $output = STDOUT): int
    {
        $force = in_array('--force', $arguments, true);
        $dsn = NotOnFire::env('NOTONFIRE_DSN');
        $environment = NotOnFire::environment();
        $enabled = NotOnFire::enabled();
        $analyticsId = NotOnFire::env('NOTONFIRE_ANALYTICS_ID');
        $analyticsDomains = NotOnFire::env('NOTONFIRE_ANALYTICS_DOMAINS');
        $analyticsUrl = NotOnFire::env('NOTONFIRE_ANALYTICS_URL') ?? NotOnFire::DEFAULT_ANALYTICS_URL;

        fwrite($output, "NotOnFire\n\n");
        foreach (Diagnostics::rows($dsn, $environment, $enabled, $analyticsId, $analyticsDomains, 'rendered where you echo NotOnFire::analyticsScript()') as [$label, $value]) {
            fwrite($output, sprintf("  %-16s %s\n", $label, $value));
        }
        fwrite($output, "\n");

        $status = 0;

        if ($dsn !== null && (($enabled && ! NotOnFire::isSilent($environment)) || $force)) {
            $eventId = Diagnostics::sendTestEvent($dsn, $environment);

            if ($eventId === null) {
                fwrite($output, "The test event was not accepted. Check the DSN and that this machine can reach it.\n");
                $status = 1;
            } else {
                fwrite($output, "Test event sent ({$eventId}). It shows up in the NotOnFire dashboard within a minute.\n");
            }
        } elseif ($dsn !== null) {
            fwrite($output, "No test event sent from here. Run again with --force to send one anyway.\n");
        }

        if ($analyticsId !== null) {
            fwrite($output, "\nAnalytics tag, for the <head> of every page:\n\n  ".NotOnFire::analyticsTag($analyticsId, $analyticsDomains, $analyticsUrl)."\n");
        }

        return $status;
    }
}
