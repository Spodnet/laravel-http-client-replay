<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Enums;

enum StorageDriver: string
{
    case File = 'file';
    case Disk = 'disk';
    case Database = 'database';
}
