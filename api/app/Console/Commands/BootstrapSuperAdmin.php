<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * S02A STEP 1 (S01 AUDIT §5 item 7): the production path to the FIRST
 * super_admin. Refuses if any active super_admin exists — every later grant
 * goes through the audited CapabilityService flow, never this command.
 *
 * The created credential is a STANDING GO-LIVE ITEM: rotate or remove before
 * production (S10 readiness). The password is displayed exactly once.
 */
class BootstrapSuperAdmin extends Command
{
    protected $signature = 'bootstrap:super-admin {--email=} {--name=Academy Administrator}';

    protected $description = 'Create the first academy administrator with the super_admin capability (refuses if one exists)';

    public function handle(AuditService $audit): int
    {
        $existing = DB::table('admin_capabilities')
            ->where('capability', 'super_admin')
            ->whereNull('revoked_at')
            ->count();
        if ($existing > 0) {
            $this->error("REFUSED: {$existing} active super_admin grant(s) already exist.");
            $this->error('Additional grants go through the audited capability flow, never bootstrap.');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email', 'unique:users,email']],
        );
        if ($validator->fails()) {
            $this->error('REFUSED: '.$validator->errors()->first('email'));

            return self::FAILURE;
        }

        $password = Str::password(24);

        $user = DB::transaction(function () use ($audit, $email, $password): User {
            $user = User::query()->create([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'academy_admin',
                'email_verified_at' => now(), // bootstrap credential; rotation is the control
            ]);
            DB::table('admin_capabilities')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'capability' => 'super_admin',
                'granted_by' => $user->id, // self-granted at bootstrap, by definition
                'granted_at' => now(),
            ]);

            $audit->record(
                'user', (string) $user->id, 'bootstrap.super_admin',
                toState: 'super_admin',
                reason: 'first super_admin created via bootstrap:super-admin — STANDING CREDENTIAL, rotate or remove before go-live (S10)',
                actor: $user,
            );
            $audit->record(
                'user', (string) $user->id, 'capability.granted',
                toState: 'super_admin',
                payloadAfter: ['capability' => 'super_admin', 'bootstrap' => true, 'grantor_id' => $user->id, 'grantee_id' => $user->id],
                actor: $user,
            );

            return $user;
        });

        $this->info("Super admin created: {$user->email} (user id {$user->id})");
        $this->newLine();
        $this->line('One-time password (shown ONCE, never stored in clear):');
        $this->line("  {$password}");
        $this->newLine();
        $this->warn('STANDING GO-LIVE ITEM: rotate or remove this credential before production (S10).');

        return self::SUCCESS;
    }
}
