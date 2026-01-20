<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddForeignKeyFieldsToDbFieldExtras extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('db_field_extras', function (Blueprint $table) {
            $table->boolean('is_foreign_key')->default(0);
            $table->string('ref_table')->nullable();
            $table->string('ref_field')->nullable();
            $table->string('ref_on_update')->nullable();
            $table->string('ref_on_delete')->nullable();
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
            $table->dropColumn([
                'is_foreign_key',
                'ref_table',
                'ref_field',
                'ref_on_update',
                'ref_on_delete',
            ]);
        });
    }
}
