<?php

namespace App\Enums;

enum UserRoleName: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';
    case Support = 'support';
    case Customer = 'customer';
}
