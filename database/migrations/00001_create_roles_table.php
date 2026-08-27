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
        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = config('pbac.column_names.organisation_foreign_key', 'organisation_id');
        $primaryKeyType = config('pbac.keys.primary_key_type', 'id');
        $organisationKeyType = config('pbac.keys.organisation_key_type', 'id');

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($organisationEnabled, $organisationForeignKey, $organisationKeyType, $primaryKeyType) {
            $this->addPrimaryKey($table, 'id', $primaryKeyType);

            if ($organisationEnabled) {
                $this->addKeyColumn($table, $organisationForeignKey, $organisationKeyType, nullable: true);
                $table->index($organisationForeignKey, 'roles_organisation_foreign_key_index');
            }

            $table->string('name');
            $table->timestamps();
        });

        if ($organisationEnabled && Schema::getConnection()->getDriverName() === 'pgsql') {
            $roles = $this->quoteIdentifier($tableNames['roles']);
            $organisation = $this->quoteIdentifier($organisationForeignKey);

            DB::statement("CREATE UNIQUE INDEX {$this->quoteIdentifier('roles_global_name_unique')} ON {$roles} ({$this->quoteIdentifier('name')}) WHERE {$organisation} IS NULL");
            DB::statement("CREATE UNIQUE INDEX {$this->quoteIdentifier('roles_organisation_name_unique')} ON {$roles} ({$organisation}, {$this->quoteIdentifier('name')}) WHERE {$organisation} IS NOT NULL");

            return;
        }

        Schema::table($tableNames['roles'], function (Blueprint $table) use ($organisationEnabled, $organisationForeignKey) {
            $columns = $organisationEnabled ? [$organisationForeignKey, 'name'] : ['name'];

            $table->unique($columns, 'roles_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pbac.table_names.roles', 'roles'));
    }

    private function addPrimaryKey(Blueprint $table, string $column, string $type): void
    {
        match ($type) {
            'uuid' => $table->uuid($column)->primary(),
            'ulid' => $table->ulid($column)->primary(),
            default => $table->id($column),
        };
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
