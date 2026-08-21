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
        $duplicates = DB::table('social_accounts')
            ->select('workspace_id', 'platform', 'platform_user_id')
            ->groupBy('workspace_id', 'platform', 'platform_user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('social_accounts')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('platform', $duplicate->platform)
                ->where('platform_user_id', $duplicate->platform_user_id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            $keep = $rows->first();

            foreach ($rows->skip(1) as $row) {
                DB::table('post_platforms')
                    ->where('social_account_id', $row->id)
                    ->update(['social_account_id' => $keep->id]);

                DB::table('social_accounts')->where('id', $row->id)->delete();
            }
        }

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
};
