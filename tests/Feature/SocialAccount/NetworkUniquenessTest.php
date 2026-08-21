<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
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

test('connectIdentity refuses to repoint the reconnect target at another identity', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-b',
        ['username' => 'new', 'status' => Status::Connected],
        $account,
    ))->toThrow(NetworkAlreadyConnectedException::class);

    expect($account->fresh()->platform_user_id)->toBe('ig-a')
        ->and($account->fresh()->username)->toBe('old')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('connectIdentity keeps posts on the card when a stray identity is authorized', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-brand',
        'username' => 'brand',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::X,
        'x-personal',
        [
            'username' => 'personal',
            'status' => Status::Connected,
            'access_token' => 'personal-token',
        ],
        $account,
    ))->toThrow(NetworkAlreadyConnectedException::class);

    expect($account->fresh()->platform_user_id)->toBe('x-brand')
        ->and($account->fresh()->username)->toBe('brand')
        ->and($this->workspace->socialAccounts()->where('platform_user_id', 'x-personal')->exists())->toBeFalse();
});

test('connectIdentity still reconnects the same identity across a network variant', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-same',
        'username' => 'old',
    ]);

    $updated = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::LinkedInPage,
        'li-same',
        ['username' => 'new', 'status' => Status::Connected],
        $account,
    );

    expect($updated->id)->toBe($account->id)
        ->and($updated->platform)->toBe(Platform::LinkedInPage)
        ->and($updated->username)->toBe('new')
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

test('the observer leaves a missing platform to the database instead of a type error', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $account = new SocialAccount([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'no-platform',
    ]);

    expect(fn () => $account->save())
        ->toThrow(QueryException::class)
        ->and(fn () => $account->save())->not->toThrow(TypeError::class);
});
