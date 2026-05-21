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
        $primaryKeyType = config('pbac.keys.primary_key_type', 'id');

        Schema::create($tableNames['permissions'], function (Blueprint $table) use ($primaryKeyType) {
            $this->addPrimaryKey($table, 'id', $primaryKeyType);
            $table->string('name');
            $table->timestamps();

            $table->unique(['name'], 'permissions_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pbac.table_names.permissions', 'permissions'));
    }

    private function addPrimaryKey(Blueprint $table, string $column, string $type): void
    {
        match ($type) {
            'uuid' => $table->uuid($column)->primary(),
            'ulid' => $table->ulid($column)->primary(),
            default => $table->id($column),
        };
    }
};
