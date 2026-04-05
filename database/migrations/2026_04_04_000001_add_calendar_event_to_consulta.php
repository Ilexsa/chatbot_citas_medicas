<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta', function (Blueprint $table) {
            $table->string('id_evento_calendar')->nullable()->after('id_usuario_del')
                ->comment('ID del evento en Google Calendar para sincronización');
        });
    }

    public function down(): void
    {
        Schema::table('consulta', function (Blueprint $table) {
            $table->dropColumn('id_evento_calendar');
        });
    }
};
