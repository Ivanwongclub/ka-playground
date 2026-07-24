<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Leo review, 24 Jul: directory.view -> member_directory.view. The name carries
// the child-safety boundary (member directory = first-generation adults only,
// FR058/OD-1; students never appear — FR056). Fresh databases seed the new key
// from the matrix source; this renames it in databases seeded earlier.
// FK-safe order: insert new parent, repoint children, delete old parent.
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('permissions')->where('key', 'directory.view')->exists();
        if (! $old) {
            return; // freshly-seeded database — already correct
        }

        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore(['key' => 'member_directory.view', 'created_at' => now()]);
            DB::table('role_permissions')->where('permission_key', 'directory.view')
                ->update(['permission_key' => 'member_directory.view']);
            DB::table('capability_permissions')->where('permission_key', 'directory.view')
                ->update(['permission_key' => 'member_directory.view']);
            DB::table('permissions')->where('key', 'directory.view')->delete();
        });
    }

    public function down(): void
    {
        // Deliberately irreversible: the un-scoped name is the hazard.
    }
};
