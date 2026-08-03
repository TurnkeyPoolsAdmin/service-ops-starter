<?php

use App\Models\Employee;
use App\Services\Intake\SalesIntakeService;
use Illuminate\Database\Migrations\Migration;

/**
 * The system actor auto-intaken sales leads are created BY.
 *
 * `jobs.created_by` is a foreign key to `employees` and every lifecycle call
 * takes an Employee actor, so the webhook needs a row to act as. Making the
 * column nullable instead would have meant auditing every `createdBy?->name`
 * read and every activity-log `causedBy()` — this row is the smaller change and
 * it makes the job timeline read plainly ("Sales Dashboard — Lead created from
 * sales dashboard") instead of blank.
 *
 * It is deliberately inactive and login-less, and carries NO job functions, so
 * it can never appear in a salesperson / technician / dispatcher picker or in
 * any Employee::active() query. SalesIntakeService::systemActor() resolves it by
 * email and re-creates it if it ever goes missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Employee::withTrashed()->firstOrCreate(
            ['email' => SalesIntakeService::SYSTEM_ACTOR_EMAIL],
            [
                'name' => SalesIntakeService::SYSTEM_ACTOR_NAME,
                'job_functions' => [],
                'can_login' => false,
                'active' => false,
            ],
        );
    }

    public function down(): void
    {
        // Deliberately not deleted: jobs.created_by references it.
    }
};
