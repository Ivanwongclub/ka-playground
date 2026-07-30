<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // account.provenance (S04C-4): every account traces to an audited origin.
        DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'user',
            'entity_id' => (string) $user->id, 'action' => 'user.created', 'to_state' => 'registered',
            'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
            'payload_after' => json_encode(['origin' => 'seed_default']),
        ]);
    }
}
