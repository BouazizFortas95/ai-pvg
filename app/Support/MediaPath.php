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

    /**
     * Resolve a stored media path to a file that exists on disk, or null.
     *
     * Unlike resolve(), this never returns a guessed path. It also checks the
     * public disk, because audio rows may store paths like `public/voices/x.wav`.
     * The renderer and the production preflight both use this, so a file the
     * preflight accepts is a file the render can actually load.
     */
    public static function existing(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        $clean = str_replace(['public/', 'public\\'], '', $path);

        $candidates = [
            $path,
            storage_path('app/'.$path),
            storage_path('app/public/'.$path),
            storage_path('app/public/'.$clean),
            public_path($clean),
            base_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
