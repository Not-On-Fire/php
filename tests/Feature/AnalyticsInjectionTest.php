<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

function analyticsConfiguration(array $overrides = []): array
{
    return ['notonfire.analytics' => [
        'id' => '9f3c2b1a-0000-4000-8000-000000000001',
        'domains' => 'shop.example.test',
        'url' => 'https://analytics.example.test',
        'inject' => true,
        'except' => ['admin', 'admin/*'],
        ...$overrides,
    ]];
}

function page(string $body = 'Shop'): string
{
    return '<!doctype html><html><head><title>Shop</title></head><body>'.$body.'</body></html>';
}

beforeEach(function () {
    $this->boot(configuration: analyticsConfiguration());
});

test('the tag is added before the closing head of an HTML page', function () {
    Route::middleware('web')->get('/', fn () => page());

    $response = $this->get('/');

    expect($response->getContent())->toContain('<script defer src="https://analytics.example.test/script.js" data-website-id="9f3c2b1a-0000-4000-8000-000000000001" data-domains="shop.example.test" data-do-not-track="true" data-exclude-search="true" data-exclude-hash="true" data-performance="true" referrerpolicy="no-referrer"></script>'."\n</head>");
});

test('responses that are not a full HTML page are left alone', function (string $path, Closure $route, array $headers = []) {
    Route::middleware('web')->get($path, $route);

    $response = $this->withHeaders($headers)->get($path);

    expect((string) $response->getContent())->not->toContain('script.js');
})->with([
    'JSON' => ['/api-like', fn () => fn () => response()->json(['html' => '</head>'])],
    'a redirect' => ['/away', fn () => fn () => redirect('/')],
    'a failing page' => ['/missing', fn () => fn () => response(page(), 404)],
    'an admin area' => ['/admin/orders', fn () => fn () => page()],
    'a Livewire update' => ['/livewire', fn () => fn () => page(), ['X-Livewire' => 'true']],
    'an XHR request' => ['/partial', fn () => fn () => page(), ['X-Requested-With' => 'XMLHttpRequest']],
    'a fragment without head' => ['/fragment', fn () => fn () => '<div>Shop</div>'],
]);

test('a layout that already renders the tag with the directive does not get a second one', function () {
    Route::middleware('web')->get('/', fn () => Blade::render('<html><head>@notonfireAnalytics</head><body></body></html>'));

    $response = $this->get('/');

    expect(substr_count((string) $response->getContent(), 'script.js'))->toBe(1);
});

test('attribute values are escaped', function () {
    config()->set('notonfire.analytics.domains', 'shop.example.test" onload="alert(1)');
    Route::middleware('web')->get('/', fn () => page());

    $response = $this->get('/');

    expect((string) $response->getContent())->toContain('data-domains="shop.example.test&quot; onload=&quot;alert(1)"')
        ->not->toContain('" onload="alert(1)"');
});

describe('without injection', function () {
    beforeEach(function () {
        $this->boot(configuration: analyticsConfiguration(['inject' => false]));
    });

    test('only the directive renders the tag', function () {
        Route::middleware('web')->get('/plain', fn () => page());
        Route::middleware('web')->get('/directive', fn () => Blade::render('<html><head>@notonfireAnalytics</head></html>'));

        expect((string) $this->get('/plain')->getContent())->not->toContain('script.js')
            ->and((string) $this->get('/directive')->getContent())->toContain('data-website-id="9f3c2b1a-0000-4000-8000-000000000001"');
    });
});

describe('under tests', function () {
    beforeEach(function () {
        $this->boot('testing', analyticsConfiguration());
    });

    test('no tag is rendered', function () {
        Route::middleware('web')->get('/', fn () => page());

        expect((string) $this->get('/')->getContent())->not->toContain('script.js');
    });
});
