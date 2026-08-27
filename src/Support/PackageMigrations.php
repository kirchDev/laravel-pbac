<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Support;

/**
 * The naming rules of the publish-only migration flow, kept out of the service provider so
 * the provider stays about wiring and these rules can be read — and tested — on their own.
 *
 * Source files are named `<sequence>_<migration>` — 00001_create_roles_table.php. The
 * sequence is the package's running order and never leaves the package: publishing splits
 * it off and stamps what remains with the publish time, so the migrations land in the
 * consumer's own timeline while keeping the order the sequence prescribes.
 */
final class PackageMigrations
{
    /**
     * Map every source migration onto the path it is published to.
     *
     * @return array<string, string> source path => published path
     */
    public static function publishMap(string $directory, ?int $publishedAt = null): array
    {
        $sources = glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($sources);

        $publishedAt ??= time();
        $map = [];

        foreach ($sources as $offset => $source) {
            $map[$source] = self::publishedPath(self::name($source), $publishedAt + $offset);
        }

        return $map;
    }

    /**
     * The part of a source filename a consumer actually sees — the sequence prefix removed.
     */
    public static function name(string $source): string
    {
        return (string) preg_replace('/^\d+_/', '', basename($source));
    }

    /**
     * Where a published migration lands.
     *
     * An already published copy keeps the filename it has, so re-running the publish never
     * leaves a consumer with two migrations creating the same table. Only a migration that
     * is not there yet gets a fresh stamp.
     */
    public static function publishedPath(string $name, int $timestamp): string
    {
        $directory = database_path('migrations');
        $existing = glob($directory.DIRECTORY_SEPARATOR.'*_'.$name) ?: [];

        return $existing[0] ?? $directory.DIRECTORY_SEPARATOR.date('Y_m_d_His', $timestamp).'_'.$name;
    }
}
