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
    protected $description = 'Enqueue Clinic and Guidance appointments at T-15.';
    protected $usage = 'synapse:appointments-enqueue-due';

    public function run(array $params): int
    {
        $clinic = \Config\Services::appointmentService()->autoCheckInTodaysPending();
        $guidance = (new GuidanceQueueService(
            new CounsellingPolicy(),
            \Config\Services::auditOutbox(),
            \Config\Services::notificationOutbox(),
        ))->enqueueDueAppointments();
        CLI::write("Due appointment enqueue complete. Clinic: {$clinic}; Guidance: {$guidance}.", 'green');
        return 0;
    }
}
