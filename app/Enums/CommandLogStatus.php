<?php

namespace App\Enums;

enum CommandLogStatus: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
