<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referral / representative / commission fields on users. All additive; the
 * referral_code is backfilled for existing users without overwriting any
 * existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 16)->nullable()->unique()->after('account_id');
            }
            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'is_representative')) {
                $table->boolean('is_representative')->default(false)->after('referred_by_user_id');
            }
            if (! Schema::hasColumn('users', 'representative_status')) {
                $table->string('representative_status', 20)->default('none')->after('is_representative');
            }
            if (! Schema::hasColumn('users', 'commission_type')) {
                $table->string('commission_type', 20)->nullable()->after('representative_status');
            }
            if (! Schema::hasColumn('users', 'commission_rate')) {
                $table->unsignedInteger('commission_rate')->nullable()->after('commission_type');
            }
            if (! Schema::hasColumn('users', 'commission_fixed_amount')) {
                $table->unsignedBigInteger('commission_fixed_amount')->nullable()->after('commission_rate');
            }
            if (! Schema::hasColumn('users', 'commission_balance')) {
                $table->bigInteger('commission_balance')->default(0)->after('commission_fixed_amount');
            }
            if (! Schema::hasColumn('users', 'total_commission_earned')) {
                $table->bigInteger('total_commission_earned')->default(0)->after('commission_balance');
            }
            if (! Schema::hasColumn('users', 'representative_approved_at')) {
                $table->timestamp('representative_approved_at')->nullable()->after('total_commission_earned');
            }
            if (! Schema::hasColumn('users', 'representative_note')) {
                $table->text('representative_note')->nullable()->after('representative_approved_at');
            }
        });

        // Backfill referral codes for existing users (never overwrite).
        $used = DB::table('users')->whereNotNull('referral_code')->pluck('referral_code')->all();
        $used = array_flip($used);

        DB::table('users')->whereNull('referral_code')->orderBy('id')->each(function ($user) use (&$used) {
            do {
                $code = User::makeReferralCode();
            } while (isset($used[$code]));
            $used[$code] = true;
            DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->dropConstrainedForeignId('referred_by_user_id');
            }
            $table->dropColumn([
                'referral_code', 'is_representative', 'representative_status',
                'commission_type', 'commission_rate', 'commission_fixed_amount',
                'commission_balance', 'total_commission_earned',
                'representative_approved_at', 'representative_note',
            ]);
        });
    }
};
