<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Symfony\Component\Console\Output\ConsoleOutput;

class CreateDbExtrasTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();
        // Even though we take care of this scenario in the code,
        // SQL Server does not allow potential cascading loops,
        // so set the default no action and clear out created/modified by another user when deleting a user.
        $userOnDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        $output = new ConsoleOutput();
        $output->writeln("Migration driver used: $driver");

        // Database Table Extras
        if (!Schema::hasTable('db_table_extras')) {
            Schema::create(
                'db_table_extras',
                function (Blueprint $t) use ($userOnDelete) {
                    $t->increments('id');
                    $t->integer('service_id')->unsigned();
                    $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                    $t->string('table');
                    $t->string('label')->nullable();
                    $t->string('plural')->nullable();
                    $t->string('name_field', 128)->nullable();
                    $t->string('model')->nullable();
                    $t->text('description')->nullable();
                    $t->timestamp('created_date');
                    $t->timestamp('last_modified_date');
                    $t->integer('created_by_id')->unsigned()->nullable();
                    $t->foreign('created_by_id')->references('id')->on('user')->onDelete($userOnDelete);
                    $t->integer('last_modified_by_id')->unsigned()->nullable();
                    $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($userOnDelete);
                }
            );
        }

        // Database Field Extras
        if (!Schema::hasTable('db_field_extras')) {
            Schema::create(
                'db_field_extras',
                function (Blueprint $t) use ($userOnDelete) {
                    $t->increments('id');
                    $t->integer('service_id')->unsigned();
                    $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                    $t->string('table');
                    $t->string('field');
                    $t->string('label')->nullable();
                    $t->string('extra_type')->nullable();
                    $t->text('description')->nullable();
                    $t->text('picklist')->nullable();
                    $t->text('validation')->nullable();
                    $t->text('client_info')->nullable();
                    $t->timestamp('created_date');
                    $t->timestamp('last_modified_date');
                    $t->integer('created_by_id')->unsigned()->nullable();
                    $t->foreign('created_by_id')->references('id')->on('user')->onDelete($userOnDelete);
                    $t->integer('last_modified_by_id')->unsigned()->nullable();
                    $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($userOnDelete);
                }
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Drop created tables in reverse order

        // Database Extras
        Schema::dropIfExists('db_table_extras');
        // Database Extras
        Schema::dropIfExists('db_field_extras');
    }
}
