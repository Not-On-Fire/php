<?php

namespace NotOnFire\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds the analytics tag before </head> of every page the web group renders.
 *
 * Only a successful, complete HTML document qualifies: JSON, redirects,
 * downloads, streamed responses, Livewire and other XHR updates are left
 * alone, and so are the admin areas in `notonfire.analytics.except`. A page
 * that already carries the tag, because its layout uses the
 * `@notonfireAnalytics` directive, does not get a second one.
 */
class InjectAnalyticsScript
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->qualifies($request, $response)) {
            return $response;
        }

        $tag = AnalyticsTag::render();
        $content = $response->getContent();

        if ($tag === '' || ! is_string($content) || str_contains($content, 'data-website-id="'.e((string) Settings::analyticsId()).'"')) {
            return $response;
        }

        $head = strripos($content, '</head>');

        if ($head === false) {
            return $response;
        }

        $response->setContent(substr_replace($content, $tag."\n", $head, 0));

        if ($response->headers->has('Content-Length')) {
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    private function qualifies(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if (! $response->isSuccessful() || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        if ($request->ajax() || $request->headers->has('X-Livewire') || $request->expectsJson()) {
            return false;
        }

        $except = config('notonfire.analytics.except', []);

        return ! (is_array($except) && $except !== [] && $request->is(...$except));
    }
}
