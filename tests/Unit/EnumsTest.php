<?php

declare(strict_types=1);

use Spodnet\HttpClientReplay\Enums\Mode;
use Spodnet\HttpClientReplay\Enums\StorageDriver;

it('provides expected mode values and helpers', function () {
    expect(Mode::Off->value)->toBe('off')
        ->and(Mode::Record->value)->toBe('record')
        ->and(Mode::Replay->value)->toBe('replay')
        ->and(Mode::Auto->value)->toBe('auto');

    expect(Mode::Off->isOff())->toBeTrue()
        ->and(Mode::Off->isRecording())->toBeFalse()
        ->and(Mode::Record->isRecording())->toBeTrue()
        ->and(Mode::Replay->isReplaying())->toBeTrue()
        ->and(Mode::Auto->isAuto())->toBeTrue();
});

it('provides expected storage driver values', function () {
    expect(StorageDriver::File->value)->toBe('file')
        ->and(StorageDriver::Disk->value)->toBe('disk')
        ->and(StorageDriver::Database->value)->toBe('database');
});
