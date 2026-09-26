<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\QueueService as GuidanceQueueService;

final class AppointmentsEnqueueDue extends BaseCommand
{
    protected $group = 'SYNAPSE';
    protected $name = 'synapse:appointments-enqueue-due';
    protected $description = 'Enqueue Guidance appointments at T-15 (clinic check-in is staff-actioned as of 2026-09-25).';
    protected $usage = 'synapse:appointments-enqueue-due';

    public function run(array $params): int
    {
        $guidance = (new GuidanceQueueService(
            new CounsellingPolicy(),
            \Config\Services::auditOutbox(),
            \Config\Services::notificationOutbox(),
        ))->enqueueDueAppointments();
        CLI::write("Due appointment enqueue complete. Guidance: {$guidance}.", 'green');
        return 0;
    }
}
