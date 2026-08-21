<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->collapseDuplicateIdentities();

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'platform', 'platform_user_id'],
                'social_accounts_workspace_platform_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique('social_accounts_workspace_platform_identity_unique');
        });
    }

    /**
     * Installs that predate the unique index could store the same identity twice
     * (the network guard was bypassed for multi-account installs, and Pinterest
     * always created a fresh row). Keep the newest row per identity, move its
     * posts over, and drop the stale duplicates so the index can be created.
     */
    private function collapseDuplicateIdentities(): void
    {
        $duplicates = DB::table('social_accounts')
            ->select('workspace_id', 'platform', 'platform_user_id')
            ->groupBy('workspace_id', 'platform', 'platform_user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $identity = DB::table('social_accounts')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('platform', $duplicate->platform)
                ->where('platform_user_id', $duplicate->platform_user_id);

            $ids = (clone $identity)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            $keepId = array_shift($ids);

            if ($keepId === null || $ids === []) {
                continue;
            }

            DB::table('post_platforms')
                ->whereIn('social_account_id', $ids)
                ->update(['social_account_id' => $keepId]);

            DB::table('social_accounts')->whereIn('id', $ids)->delete();
        }
    }
};
