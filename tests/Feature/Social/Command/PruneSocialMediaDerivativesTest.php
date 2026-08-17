<?php

declare(strict_types=1);

use App\Support\Social\SocialMediaDerivativeDirectory;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

test('command deletes derivatives older than the pull window from every registered directory', function () {
    $oldCrop = SocialMediaDerivativeDirectory::CROPS.'/old.jpg';
    $oldTikTok = SocialMediaDerivativeDirectory::TIKTOK_PHOTOS.'/old.jpg';

    Storage::put($oldCrop, 'fake-bytes');
    Storage::put($oldTikTok, 'fake-bytes');
    touch(Storage::path($oldCrop), now()->subHours(2)->getTimestamp());
    touch(Storage::path($oldTikTok), now()->subHours(2)->getTimestamp());

    $this->artisan('social:prune-derivatives')->assertExitCode(0);

    Storage::assertMissing($oldCrop);
    Storage::assertMissing($oldTikTok);
});

test('command leaves recent derivatives alone', function () {
    $recent = SocialMediaDerivativeDirectory::CROPS.'/recent.jpg';

    Storage::put($recent, 'fake-bytes');

    $this->artisan('social:prune-derivatives')->assertExitCode(0);

    Storage::assertExists($recent);
});

test('command is a no-op when no derivative directories exist yet', function () {
    $this->artisan('social:prune-derivatives')->assertExitCode(0);
});
