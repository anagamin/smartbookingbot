<?php

use App\Models\User;
use App\Support\BookingSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('booking_slug', 64)->nullable()->unique()->after('services_description');
        });

        User::query()->whereNull('booking_slug')->orderBy('id')->each(function (User $user): void {
            $user->update(['booking_slug' => BookingSlug::generateUnique($user->name)]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('booking_slug');
        });
    }
};
