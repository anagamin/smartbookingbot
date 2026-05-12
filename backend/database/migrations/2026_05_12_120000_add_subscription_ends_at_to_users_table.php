<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('subscription_ends_at')->nullable()->after('next_billing_at');
        });

        $price = (int) config('smartbooking.subscription_price_kopecks', 100_000);
        $now = Carbon::now();

        DB::table('users')->orderBy('id')->chunkById(100, function ($rows) use ($price, $now) {
            foreach ($rows as $row) {
                $trial = $row->trial_ends_at ? Carbon::parse($row->trial_ends_at) : null;
                $next = $row->next_billing_at ? Carbon::parse($row->next_billing_at) : null;
                $balance = (int) $row->balance_kopecks;

                if ($trial && $now->lt($trial)) {
                    $ends = $trial;
                } elseif ($balance >= $price && $next) {
                    $ends = $next->lt($now) ? $now->copy()->addDay() : $next;
                } elseif ($trial) {
                    $ends = $trial;
                } else {
                    $ends = $now;
                }

                DB::table('users')->where('id', $row->id)->update([
                    'subscription_ends_at' => $ends,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subscription_ends_at');
        });
    }
};
