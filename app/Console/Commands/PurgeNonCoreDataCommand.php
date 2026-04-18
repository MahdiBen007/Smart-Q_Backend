<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeNonCoreDataCommand extends Command
{
    protected $signature = 'data:purge-non-core {--force : Run the purge without confirmation}';

    protected $description = 'Purge all table data except core entities: users, services, branches, and companies.';

    /**
     * Tables that should never be purged by this command.
     *
     * @var array<int, string>
     */
    protected array $protectedTables = [
        'users',
        'services',
        'branches',
        'companies',
        'migrations',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will delete all non-core data. Continue?')) {
            $this->warn('Purge cancelled.');

            return self::INVALID;
        }

        $databaseName = (string) DB::getDatabaseName();
        $rows = DB::select('SHOW TABLES');

        if ($rows === []) {
            $this->info('No tables found.');

            return self::SUCCESS;
        }

        $column = 'Tables_in_'.$databaseName;
        $allTables = array_values(array_map(
            static fn (object $row): string => (string) ($row->{$column} ?? ''),
            $rows
        ));
        $allTables = array_values(array_filter($allTables, static fn (string $table): bool => $table !== ''));

        $tablesToPurge = array_values(array_diff($allTables, $this->protectedTables));

        if ($tablesToPurge === []) {
            $this->info('No non-core tables to purge.');

            return self::SUCCESS;
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tablesToPurge as $table) {
                $wrappedTable = DB::getQueryGrammar()->wrapTable($table);
                DB::statement("TRUNCATE TABLE {$wrappedTable}");
                $this->line("Purged: {$table}");
            }
        } catch (\Throwable $e) {
            $this->error('Purge failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Non-core data purge completed successfully.');
        $this->info('Protected tables: '.implode(', ', $this->protectedTables));

        return self::SUCCESS;
    }
}
