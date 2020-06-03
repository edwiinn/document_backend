<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UserDocument extends Model
{
    protected $table = 'users_documents';

    protected $fillable = ['user_id', 'document_id'];

    public function document()
    {
        return $this->hasOne(Document::class,'id','document_id');
    }
}
