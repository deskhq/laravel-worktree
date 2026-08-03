<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\DoctorCommand as HostDoctorCommand;

class DoctorCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:doctor
        {arguments?* : Options forwarded to the host binary}
        {--json : Emit the whole report as one object}';

    /** @var string */
    protected $description = 'Check this repository and machine against every pre-flight a create makes — runs on the host';

    protected function hostCommand(): string
    {
        return 'doctor';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        return $this->option(HostDoctorCommand::Json) === true ? ['--'.HostDoctorCommand::Json] : [];
    }
}
