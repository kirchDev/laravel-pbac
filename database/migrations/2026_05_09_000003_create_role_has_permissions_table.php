<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('pbac.table_names');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');
        $primaryKeyType = config('pbac.keys.primary_key_type', 'id');
        $targetMorphKeyType = config('pbac.keys.target_morph_key_type', 'id');

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($permissionPivot, $primaryKeyType, $rolePivot, $tableNames, $targetMorphKey, $targetMorphKeyType) {
            $this->addKeyColumn($table, $permissionPivot, $primaryKeyType);
            $this->addKeyColumn($table, $rolePivot, $primaryKeyType);
            $table->string('target_type')->nullable();
            $this->addKeyColumn($table, $targetMorphKey, $targetMorphKeyType, nullable: true);

            $table->foreign($permissionPivot)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign($rolePivot)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->index([$rolePivot, $permissionPivot], 'role_has_permissions_role_permission_index');
            $table->index(['target_type', $targetMorphKey], 'role_has_permissions_target_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $table = $this->quoteIdentifier($tableNames['role_has_permissions']);
            $role = $this->quoteIdentifier($rolePivot);
            $permission = $this->quoteIdentifier($permissionPivot);
            $targetType = $this->quoteIdentifier('target_type');
            $targetId = $this->quoteIdentifier($targetMorphKey);

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$this->quoteIdentifier('role_has_permissions_target_pair_check')} CHECK (({$targetType} IS NULL AND {$targetId} IS NULL) OR ({$targetType} IS NOT NULL AND {$targetId} IS NOT NULL))");
            DB::statement("CREATE UNIQUE INDEX {$this->quoteIdentifier('role_has_permissions_broad_unique')} ON {$table} ({$role}, {$permission}) WHERE {$targetType} IS NULL AND {$targetId} IS NULL");
            DB::statement("CREATE UNIQUE INDEX {$this->quoteIdentifier('role_has_permissions_target_unique')} ON {$table} ({$role}, {$permission}, {$targetType}, {$targetId}) WHERE {$targetType} IS NOT NULL AND {$targetId} IS NOT NULL");

            return;
        }

        Schema::table($tableNames['role_has_permissions'], function (Blueprint $table) use ($permissionPivot, $rolePivot, $targetMorphKey) {
            $table->unique([$rolePivot, $permissionPivot, 'target_type', $targetMorphKey], 'role_has_permissions_role_permission_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pbac.table_names.role_has_permissions', 'role_has_permissions'));
    }

    private function addKeyColumn(Blueprint $table, string $column, string $type, bool $nullable = false): void
    {
        $definition = match ($type) {
            'uuid' => $table->uuid($column),
            'ulid' => $table->ulid($column),
            default => $table->unsignedBigInteger($column),
        };

        if ($nullable) {
            $definition->nullable();
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
