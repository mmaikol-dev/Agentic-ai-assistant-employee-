<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Whatsapp extends Model
{
    use HasFactory;
    
     protected $table = 'whatsapp';

    protected $fillable = [
        'to',
        'client_name',
        'store_name',
        'message',
        'cc_agents',
        'status',
        'sid',
        'type',
    ];
}

