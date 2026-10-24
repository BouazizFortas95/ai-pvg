<?php

namespace App\Support;

class MediaPath
{
    /**
     * Resolve a stored media path against the project's supported roots.
     *
     * Historical records may store absolute paths, paths relative to the
     * project root (`public/videos/...`), or paths relative to storage/app.
     */
    public static function resolve(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (RemotionRuntime::isAbsolute($path)) {
            return $path;
        }

        $candidates = [
            base_path($path),
            storage_path('app/'.$path),
            public_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path($path);
    }

    public static function exists(?string $path): bool
    {
        $resolved = self::resolve($path);

        return $resolved !== null && is_file($resolved);
    }

    public static function size(?string $path): int
    {
        $resolved = self::resolve($path);

        return $resolved !== null && is_file($resolved) ? (filesize($resolved) ?: 0) : 0;
    }
}
