<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypePermission extends Model
{
    protected $table = 'types_permission';

    protected $fillable = ['libelle', 'duree_max'];
}
