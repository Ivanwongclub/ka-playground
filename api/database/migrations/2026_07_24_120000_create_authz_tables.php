<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S01 STEP 1 — roles, permissions, capability groups (OD-1, OD-17, Spec B1/B7).
// The matrix is seeded HERE from config/permission-matrix.php: roles are a fixed
// platform taxonomy (Spec A2), not runtime data, so tests and environments get
// the matrix with migrate alone. The S01 nightly probe detects any drift.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('key')->primary(); // student · guardian · teacher · school_admin · academy_admin · member
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role_key');
            $table->string('permission_key');
            $table->primary(['role_key', 'permission_key']);
            $table->foreign('role_key')->references('key')->on('roles');
            $table->foreign('permission_key')->references('key')->on('permissions');
        });

        Schema::create('capability_permissions', function (Blueprint $table) {
            $table->string('capability'); // super_admin · configuration · finance · operations · audit_read
            $table->string('permission_key');
            $table->primary(['capability', 'permission_key']);
            $table->foreign('permission_key')->references('key')->on('permissions');
        });

        Schema::create('admin_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('capability');
            $table->foreignId('granted_by')->constrained('users');
            $table->timestampTz('granted_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();

            $table->index(['user_id', 'capability']);
        });
        // One ACTIVE grant per (user, capability) — history rows keep revoked_at
        DB::statement(match (DB::getDriverName()) {
            'pgsql', 'sqlite' => 'CREATE UNIQUE INDEX admin_capabilities_active_unique ON admin_capabilities (user_id, capability) WHERE revoked_at IS NULL',
            default => throw new RuntimeException('partial index not implemented for '.DB::getDriverName()),
        });

        // Seed the fixed matrix BEFORE touching users: existing rows take the
        // 'student' default, so roles must exist when the FK lands
        // (single source of truth: config/permission-matrix.php)
        $matrix = require config_path('permission-matrix.php');
        $now = now();

        DB::table('permissions')->insert(
            array_map(fn (string $p) => ['key' => $p, 'created_at' => $now], $matrix['permissions'])
        );
        DB::table('roles')->insert(
            array_map(fn (string $r) => ['key' => $r, 'created_at' => $now], array_keys($matrix['roles']))
        );
        foreach ($matrix['roles'] as $role => $permissions) {
            DB::table('role_permissions')->insert(
                array_map(fn (string $p) => ['role_key' => $role, 'permission_key' => $p], $permissions)
            );
        }
        foreach ($matrix['capabilities'] as $capability => $permissions) {
            $resolved = array_values(array_diff($permissions === '*' ? $matrix['permissions'] : $permissions, $matrix['capability_forbidden'] ?? []));
            DB::table('capability_permissions')->insert(
                array_map(fn (string $p) => ['capability' => $capability, 'permission_key' => $p], $resolved)
            );
        }

        // Spec B1: roles are never stacked — one role per account, at the schema
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student');
            $table->foreign('role')->references('key')->on('roles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role']);
            $table->dropColumn('role');
        });
        Schema::dropIfExists('admin_capabilities');
        Schema::dropIfExists('capability_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
