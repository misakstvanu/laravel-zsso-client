<?php

use Misakstvanu\ZssoClient\AppDirectory;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zskauting',
    ]);
});

test('a C-4 row carrying the branding keys still resolves by slug and by client id', function () {
    fakeIntegrationServer(apps: [[
        'slug' => 'zirafa',
        'client_id' => 'zirafa-client-id',
        'name' => 'Žirafa',
        'base_url' => 'https://zirafa.test',
        'login_url' => 'https://zirafa.test/auth/zsso/redirect',
        'integration_url' => 'https://zirafa.test/api/integration/v1',
        'webhook_url' => 'https://zirafa.test/api/integration/v1/webhooks',
        'icon' => '🦒',
        'color' => '#2f6b3a',
        'description' => 'Evidence účastníků akcí',
        'logo_url' => 'https://zsso.test/storage/apps/zirafa.png',
        'requires_skautis' => false,
        'min_age' => null,
    ]]);

    $directory = app(AppDirectory::class);

    expect($directory->slugForClientId('zirafa-client-id'))->toBe('zirafa')
        ->and($directory->app('zirafa'))->toMatchArray([
            'slug' => 'zirafa',
            'color' => '#2f6b3a',
            'description' => 'Evidence účastníků akcí',
            'logo_url' => 'https://zsso.test/storage/apps/zirafa.png',
        ])
        ->and($directory->urlFor('zirafa', 'integration_url'))->toBe('https://zirafa.test/api/integration/v1');
});
