<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DbVirtualField extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('db_field_extras', function (Blueprint $table) {
            $table->boolean('is_virtual')->default(0);
            $table->boolean('is_aggregate')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('db_field_extras', function (Blueprint $table) {
            $table->dropColumn('is_virtual');
            $table->dropColumn('is_aggregate');
        });
    }
}
