<?php

namespace App\Console\Commands;

use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Console\Command;

class RunQueueAutomationCommand extends Command
{
    protected $signature = 'queue:run-automation {--company=} {--grace=30}';

    protected $description = 'Sync today appointments into queue monitor, process absent serving entries, and cancel unattended past appointments.';

    public function handle(OperationalWorkflowService $workflow): int
    {
        $companyId = $this->option('company');
        $graceSeconds = max((int) $this->option('grace'), 1);

        $result = $workflow->runQueueAutomationCycle(
            $companyId !== null && $companyId !== '' ? (string) $companyId : null,
            $graceSeconds,
        );

        $this->info(sprintf(
            'Queue automation completed. Synced: %d, Requeued: %d, Cancelled: %d',
            $result['synced'] ?? 0,
            $result['requeued'] ?? 0,
            $result['cancelled'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
