<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Commands;

use Illuminate\Console\Command;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

class ClearCassettesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'http-client-replay:clear
                            {--domain= : Filter cassettes by domain}
                            {--all : Clear all recorded cassettes}
                            {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Clear recorded HTTP replay cassettes';

    public function handle(CassetteRepositoryInterface $repository): int
    {
        /** @var string|null $domain */
        $domain = $this->option('domain');
        /** @var bool $all */
        $all = (bool) $this->option('all');
        /** @var bool $force */
        $force = (bool) $this->option('force');

        $targetDescription = $domain !== null && $domain !== ''
            ? "cassettes for domain [{$domain}]"
            : 'all recorded cassettes';

        if (! $force && ! $this->confirm("Are you sure you want to delete {$targetDescription}?", true)) {
            $this->info('Clear aborted.');

            return self::SUCCESS;
        }

        $deletedCount = $repository->clear($domain);

        $this->info("Successfully deleted {$deletedCount} {$targetDescription}.");

        return self::SUCCESS;
    }
}
