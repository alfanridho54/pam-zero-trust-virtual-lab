<?php

namespace App\Enums;

enum TerminalSessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Closed = 'closed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
