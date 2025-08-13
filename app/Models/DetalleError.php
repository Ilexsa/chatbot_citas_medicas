<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleError extends Model
{
    protected $table = 'detalle_error';

    protected $fillable = [
        'wamid',
        'code',
        'title',
        'message',
        'details',
        'created_at'
    ];
}
