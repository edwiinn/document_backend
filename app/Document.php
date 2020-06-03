<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';
    
    protected $fillable = ['name', 'filepath', 'signed'];
}
