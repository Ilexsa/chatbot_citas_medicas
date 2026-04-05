<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_uso', function (Blueprint $table) {
            $table->id();
            $table->string('telefono_usuario', 30)->comment('Número WhatsApp del paciente');
            $table->unsignedBigInteger('tokens_entrada')->default(0)->comment('Tokens de prompt enviados a Gemini (suma de todas las iteraciones)');
            $table->unsignedBigInteger('tokens_salida')->default(0)->comment('Tokens de respuesta generados por Gemini');
            $table->unsignedTinyInteger('iteraciones_ia')->default(1)->comment('Cantidad de llamadas a Gemini en esta conversación');
            $table->date('fecha')->comment('Fecha de la interacción');
            $table->timestamps();

            $table->index(['fecha', 'telefono_usuario']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_uso');
    }
};
