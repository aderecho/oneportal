<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('slo_url')->nullable()->after('acs_url');
            $table->text('x509_cert')->nullable()->after('launch_url');
            $table->string('signing_algo')->default('rsa-sha256')->after('x509_cert');
            $table->string('default_relay_state')->nullable()->after('signing_algo');
        });
    }

    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropColumn(['slo_url', 'x509_cert', 'signing_algo', 'default_relay_state']);
        });
    }
};
