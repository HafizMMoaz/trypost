<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.allow_multiple_social_accounts', false);
    $this->workspace = Workspace::factory()->create();
});

test('blocks a second account of the same network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('collapses platform variants into one network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-profile',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedInPage,
        'platform_user_id' => 'li-page',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('allows different networks in the same workspace', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $x = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-a',
    ]);

    expect($x->exists)->toBeTrue();
});

test('allows the same network in different workspaces', function () {
    $other = Workspace::factory()->create();

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $other->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect($second->exists)->toBeTrue();
});

test('reconnecting the same account via updateOrCreate is allowed', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    $this->workspace->socialAccounts()->updateOrCreate(
        ['platform' => Platform::Instagram->value, 'platform_user_id' => 'ig-a'],
        ['username' => 'new', 'status' => Status::Connected],
    );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($this->workspace->socialAccounts()->first()->username)->toBe('new');
});

test('allowing multiple social accounts bypasses the one-per-network rule', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]);

    expect($second->exists)->toBeTrue();
});

test('multiple social accounts can be enabled without self-hosted mode', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]);

    expect($second->exists)->toBeTrue();
});

test('self-hosted still enforces one-per-network when multiple social accounts are disabled', function () {
    config()->set('trypost.self_hosted', true);
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('occupiesNetwork is true when the workspace already has that network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(SocialAccount::occupiesNetwork((string) $this->workspace->id, Platform::Instagram))->toBeTrue()
        ->and(SocialAccount::occupiesNetwork((string) $this->workspace->id, Platform::X))->toBeFalse();
});

test('blocks a same-id account connected via a different network variant', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'shared-ig-id',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('the same workspace platform identity cannot be stored twice', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('connectIdentity updates the reconnect target even when the identity changes', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    $updated = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-b',
        ['username' => 'new', 'status' => Status::Connected],
        $account,
    );

    expect($updated->id)->toBe($account->id)
        ->and($updated->platform_user_id)->toBe('ig-b')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('connectIdentity reconnect throws when the new identity is already taken', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-keep',
    ]);

    $move = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-move',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-keep',
        ['username' => 'taken', 'status' => Status::Connected],
        $move,
    ))->toThrow(NetworkAlreadyConnectedException::class);
});

test('connectIdentity ignores a reconnect target from another network', function () {
    $facebook = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page-1',
    ]);

    $instagram = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-new',
        [
            'username' => 'fresh',
            'status' => Status::Connected,
            'access_token' => 'ig-token',
        ],
        $facebook,
    );

    expect($instagram->id)->not->toBe($facebook->id)
        ->and($instagram->platform)->toBe(Platform::Instagram)
        ->and($facebook->fresh()->platform)->toBe(Platform::Facebook)
        ->and($this->workspace->socialAccounts()->count())->toBe(2);
});
