<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('blind_indexes')) {
            Schema::create('blind_indexes', function (Blueprint $table) {
                $table->uuidMorphs('indexable');
                $table->string('name');
                $table->string('value');

                $table->index(['name', 'value']);
                $table->unique(['indexable_type', 'indexable_id', 'name']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('blind_indexes');
    }
};
