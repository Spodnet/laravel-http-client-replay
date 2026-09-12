<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Enums;

enum Mode: string
{
    case Off = 'off';
    case Record = 'record';
    case Replay = 'replay';
    case Auto = 'auto';

    public function isOff(): bool
    {
        return $this === self::Off;
    }

    public function isRecording(): bool
    {
        return $this === self::Record;
    }

    public function isReplaying(): bool
    {
        return $this === self::Replay;
    }

    public function isAuto(): bool
    {
        return $this === self::Auto;
    }
}
