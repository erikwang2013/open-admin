<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use support\Model;

class AdminUser extends Model
{
    protected $table = 'erik_admin_user';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'username', 'password', 'real_name', 'avatar',
        'email', 'phone', 'id_card', 'status',
        'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'id_card'];
    protected $casts = [
        'status' => 'integer',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 使用 encryptable trait 自动加解密敏感字段
     */
    protected array $encryptable = ['email', 'phone', 'id_card'];

    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, 'erik_admin_user_role', 'user_id', 'role_id');
    }
}
