<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use KirchDev\Pbac\Console\MigrateFromSpatieCommand;

it('does not register the spatie migration command by default', function () {
    expect(config('pbac.commands.migrate_from_spatie'))->toBeFalse();

    $commands = app(Kernel::class)->all();

    expect($commands)->not->toHaveKey('pbac:migrate-from-spatie');
});

it('exposes the command class as a binding regardless of registration', function () {
    // The class itself is always autoloadable - the gate only controls whether it is wired into the console kernel.
    expect(class_exists(MigrateFromSpatieCommand::class))->toBeTrue();
});
