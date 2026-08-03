<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cutover for widening JobLifecycle::markLost() to always raise a
 * job_canceled Issue. It previously skipped the Issue for a job marked lost
 * straight out of pending_schedule (never booked), so the "Cancelled jobs"
 * Issues subview was silently missing every such job — the owner noticed 5
 * jobs marked lost that never showed up there. One-time: raise the issue for
 * every existing Lost job that doesn't already have one open, so history
 * lines up with the new behavior going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $jobs = DB::table('jobs')
            ->leftJoin('entities', 'entities.id', '=', 'jobs.entity_id')
            ->leftJoin('employees', 'employees.id', '=', 'jobs.technician_id')
            ->where('jobs.status', 'lost')
            ->whereNull('jobs.deleted_at')
            ->whereNotIn('jobs.id', function ($query) {
                $query->select('subject_id')
                    ->from('issues')
                    ->where('subject_type', 'App\\Models\\Job')
                    ->where('type', 'job_canceled')
                    ->where('status', 'open');
            })
            ->select([
                'jobs.id', 'jobs.job_number', 'jobs.customer_name_snapshot',
                'jobs.quote_amount_cents', 'jobs.scheduled_at', 'jobs.amount_collected_cents',
                'jobs.lost_reason', 'jobs.canceled_at',
                'entities.name as entity_name', 'employees.name as technician_name',
            ])
            ->get();

        foreach ($jobs as $job) {
            DB::table('issues')->insert([
                'type' => 'job_canceled',
                'subject_type' => 'App\\Models\\Job',
                'subject_id' => $job->id,
                'details' => json_encode([
                    'job_number' => $job->job_number,
                    'customer_name' => $job->customer_name_snapshot,
                    'entity' => $job->entity_name,
                    'quote_cents' => $job->quote_amount_cents,
                    'status' => 'lost',
                    'scheduled_at' => $job->scheduled_at,
                    'technician' => $job->technician_name,
                    'amount_collected_cents' => $job->amount_collected_cents,
                    'actor_name' => 'System backfill',
                    'reason' => $job->lost_reason,
                ]),
                'raised_by' => null,
                'status' => 'open',
                'created_at' => $job->canceled_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // One-time backfill — not reversible (can't distinguish these rows
        // from ones a real markLost() call would raise the same way).
    }
};
