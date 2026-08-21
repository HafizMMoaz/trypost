<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('youtube connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://accounts.google.com/o/oauth2/v2/auth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.youtube.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('youtube oauth callback creates account with single channel', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_channel_123',
                    'snippet' => [
                        'title' => 'My YouTube Channel',
                        'description' => 'Channel description',
                        'customUrl' => '@mychannel',
                        'thumbnails' => [
                            'default' => ['url' => null],
                        ],
                    ],
                    'statistics' => [
                        'subscriberCount' => 1000,
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube->value,
        'platform_user_id' => 'UC_channel_123',
        'username' => 'mychannel',
        'display_name' => 'My YouTube Channel',
        'status' => Status::Connected->value,
    ]);
});

test('youtube callback redirects to channel selection when multiple channels', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_channel_1',
                    'snippet' => [
                        'title' => 'Channel 1',
                        'customUrl' => '@channel1',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 500],
                ],
                [
                    'id' => 'UC_channel_2',
                    'snippet' => [
                        'title' => 'Channel 2',
                        'customUrl' => '@channel2',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 1000],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertRedirect(route('app.social.youtube.select-channel'));
    expect(session('youtube_oauth'))->not->toBeNull();
});

test('youtube callback fails when no channels found', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'No YouTube channels found. Please create a channel first.'));
});

test('youtube callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple youtube accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_channel_123',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_456');
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_another_channel',
                    'snippet' => [
                        'title' => 'Another Channel',
                        'customUrl' => '@anotherchannel',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 500],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::YouTube)->count())->toBe(2);
});

test('youtube callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('youtube channel selection creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'youtube_oauth' => [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_in' => 3600,
            'user_id' => 'google_user_123',
        ],
    ]);

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_channel_123',
                    'snippet' => [
                        'title' => 'My YouTube Channel',
                        'description' => 'Channel description',
                        'customUrl' => '@mychannel',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 1000],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.youtube.select'), [
        'channel_id' => 'UC_channel_123',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
        ->where('onboardingProgress', false)
    );

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube->value,
        'platform_user_id' => 'UC_channel_123',
        'username' => 'mychannel',
    ]);

    // After connect the session is cleared; PopupCallback sets onboardingProgress
    // inline so Inertia does not deferred-reload this select URL into /accounts.
    $this->actingAs($this->user)
        ->get(route('app.social.youtube.select-channel'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('youtube select channel returns popup callback when the session expired', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.youtube.select-channel'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('youtube channel selection fails with expired session', function () {
    // No session data

    $response = $this->actingAs($this->user)->post(route('app.social.youtube.select'), [
        'channel_id' => 'UC_channel_123',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('youtube callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_existing',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_channel_123',
                    'snippet' => [
                        'title' => 'My YouTube Channel',
                        'customUrl' => '@mychannel',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 1000],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.youtube.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::YouTube)->count())->toBe(1);
});

test('youtube reconnect keeps the original card for a single channel', function () {
    $account = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_channel_123',
        'username' => 'old',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'fresh-access-token';
    $socialiteUser->refreshToken = 'fresh-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_channel_123',
                    'snippet' => [
                        'title' => 'My YouTube Channel',
                        'customUrl' => '@mychannel',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 1000],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.youtube.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($this->workspace->socialAccounts()->where('platform', Platform::YouTube)->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('mychannel');
});

test('youtube reconnect shows channel_not_found when the channel is missing', function () {
    $account = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_missing',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google_user_123');
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_other',
                    'snippet' => [
                        'title' => 'Other Channel',
                        'customUrl' => '@other',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 1],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.youtube.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.channel_not_found'))
        );
});

test('youtube select refuses a channel outside the reconnect target', function () {
    $account = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_target',
        'username' => 'target',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
        'youtube_oauth' => [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_in' => 3600,
            'user_id' => 'google_user_123',
            'reconnect_id' => $account->id,
        ],
    ]);

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_other',
                    'snippet' => [
                        'title' => 'Other Channel',
                        'customUrl' => '@other',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 5],
                ],
                [
                    'id' => 'UC_target',
                    'snippet' => [
                        'title' => 'Target Channel',
                        'customUrl' => '@target',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 10],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.youtube.select'), ['channel_id' => 'UC_other'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.channel_not_found'))
        );

    expect($account->fresh()->platform_user_id)->toBe('UC_target')
        ->and($account->fresh()->access_token)->toBe('expired-token')
        ->and($this->workspace->socialAccounts()->where('platform_user_id', 'UC_other')->exists())->toBeFalse();
});

test('youtube select connects the reconnect target when it is the chosen channel', function () {
    $account = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_target',
        'username' => 'stale',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
        'youtube_oauth' => [
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 3600,
            'user_id' => 'google_user_123',
            'reconnect_id' => $account->id,
        ],
    ]);

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [
                [
                    'id' => 'UC_other',
                    'snippet' => [
                        'title' => 'Other Channel',
                        'customUrl' => '@other',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 5],
                ],
                [
                    'id' => 'UC_target',
                    'snippet' => [
                        'title' => 'Target Channel',
                        'customUrl' => '@target',
                        'thumbnails' => ['default' => ['url' => null]],
                    ],
                    'statistics' => ['subscriberCount' => 10],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.youtube.select'), ['channel_id' => 'UC_target'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('target')
        ->and($this->workspace->socialAccounts()->where('platform', Platform::YouTube)->count())->toBe(1);
});
