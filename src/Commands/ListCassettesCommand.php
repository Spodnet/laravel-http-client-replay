<?php

declare(strict_types=1);

namespace Spodnet\HttpClientReplay\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Spodnet\HttpClientReplay\Contracts\CassetteRepositoryInterface;

class ListCassettesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'http-client-replay:list';

    /**
     * The console command description.
     */
    protected $description = 'List all recorded HTTP replay cassettes with size, age, and request signature';

    public function handle(CassetteRepositoryInterface $repository): int
    {
        $cassettes = $repository->all();

        if (empty($cassettes)) {
            $this->info('No recorded HTTP replay cassettes found.');

            return self::SUCCESS;
        }

        $rows = array_map(function (array $cassette): array {
            $age = $cassette['updated_at'] > 0
                ? Carbon::createFromTimestamp($cassette['updated_at'])->diffForHumans()
                : 'Unknown';

            $size = $this->formatBytes($cassette['size']);
            $status = (string) $cassette['status'];

            return [
                $cassette['identifier'],
                $cassette['signature'],
                $status,
                $size,
                $age,
            ];
        }, $cassettes);

        $this->table(
            ['Cassette', 'Signature', 'Status', 'Size', 'Age'],
            $rows,
        );

        $this->info('Total cassettes: '.count($cassettes));

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
