<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_mode', 16)->default('solo')->after('name');
        });

        Schema::create('masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('master_id')->nullable()->after('user_id')->constrained('masters')->cascadeOnDelete();
        });

        Schema::table('working_hours', function (Blueprint $table) {
            $table->foreignId('master_id')->nullable()->after('user_id')->constrained('masters')->cascadeOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('master_id')->nullable()->after('user_id')->constrained('masters')->nullOnDelete();
        });

        DB::table('users')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                $masterId = DB::table('masters')->insertGetId([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('services')->where('user_id', $user->id)->update(['master_id' => $masterId]);
                DB::table('working_hours')->where('user_id', $user->id)->update(['master_id' => $masterId]);
                DB::table('appointments')->where('user_id', $user->id)->update(['master_id' => $masterId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_id');
        });

        Schema::table('working_hours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_id');
        });

        Schema::dropIfExists('masters');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('business_mode');
        });
    }
};
