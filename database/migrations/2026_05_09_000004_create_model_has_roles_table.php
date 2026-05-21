<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('pbac.table_names');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $modelMorphKey = config('pbac.column_names.model_morph_key', 'model_id');
        $primaryKeyType = config('pbac.keys.primary_key_type', 'id');
        $modelMorphKeyType = config('pbac.keys.model_morph_key_type', 'id');

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($modelMorphKey, $modelMorphKeyType, $primaryKeyType, $rolePivot, $tableNames) {
            $this->addKeyColumn($table, $rolePivot, $primaryKeyType);
            $table->string('model_type');
            $this->addKeyColumn($table, $modelMorphKey, $modelMorphKeyType);

            // Cascade on delete is intentional: an assignment only makes sense while
            // its role exists. Note there is no FK on (model_type, model_id) — that
            // side is polymorphic, so cleanup when a host model (e.g. User) is
            // deleted is the application's responsibility. Hook into the model's
            // deleting/deleted events or run a periodic prune job.
            $table->foreign($rolePivot)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->index(['model_type', $modelMorphKey], 'model_has_roles_model_index');
            $table->unique([$rolePivot, 'model_type', $modelMorphKey], 'model_has_roles_role_model_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pbac.table_names.model_has_roles', 'model_has_roles'));
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
};
