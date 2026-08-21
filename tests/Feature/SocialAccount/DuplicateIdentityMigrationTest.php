<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Installs that predate the unique index could hold the same identity twice, so
 * the migration has to collapse them before the index can be created.
 */
beforeEach(function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $this->migration = require database_path(
        'migrations/2026_08_21_130941_add_workspace_platform_identity_unique_to_social_accounts_table.php',
    );

    Schema::table('social_accounts', function (Blueprint $table) {
        $table->dropUnique('social_accounts_workspace_platform_identity_unique');
    });

    $this->workspace = Workspace::factory()->create();
});

test('it collapses duplicate identities and keeps the newest row', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'username' => 'older',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'username' => 'newer',
        'created_at' => now(),
    ]);

    $this->migration->up();

    expect(SocialAccount::whereKey($newer->id)->exists())->toBeTrue()
        ->and(SocialAccount::whereKey($older->id)->exists())->toBeFalse()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('it moves posts from the dropped duplicate onto the surviving account', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $older->id,
        'platform' => Platform::Pinterest,
    ]);

    $this->migration->up();

    expect($platform->fresh()->social_account_id)->toBe($newer->id);
});

test('it leaves distinct identities untouched', function () {
    $first = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-2',
    ]);

    $this->migration->up();

    expect(SocialAccount::whereKey($first->id)->exists())->toBeTrue()
        ->and(SocialAccount::whereKey($second->id)->exists())->toBeTrue();
});

test('it restores the unique index so duplicates cannot come back', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $this->migration->up();

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
