<?php

namespace App\Acl\Eloquent;

use Jenssegers\Mongodb\Eloquent\Model;

class Role extends Model
{
    protected $table = 'acl_role';

    protected $fillable = array(
        'name',
        'permissions',
        'project_key',
        'description'
    );
}
