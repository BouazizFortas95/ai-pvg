<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Resolves Remotion workspace paths and local media binaries.
 *
 * The bundled Remotion dependencies ship Chrome headless shell, FFmpeg and
 * FFprobe. Prefer those so verification does not depend on machine-wide
 * installations, while still allowing explicit env overrides.
 */
class RemotionRuntime
{
    public static function directory(): string
    {
        $configured = config('remotion.directory');

        return $configured ? self::absolute((string) $configured) : base_path('remotion');
    }

    public static function cliPath(): string
    {
        $configured = config('remotion.cli');

        if ($configured) {
            return self::isAbsolute((string) $configured)
                ? (string) $configured
                : self::join(self::directory(), (string) $configured);
        }

        return self::join(self::directory(), 'dist/render-cli.js');
    }

    public static function entryPoint(): string
    {
        $configured = config('remotion.entry_point');

        if ($configured) {
            return self::isAbsolute((string) $configured)
                ? (string) $configured
                : self::join(self::directory(), (string) $configured);
        }

        return self::join(self::directory(), 'src/index.ts');
    }

    public static function nodeBinary(): string
    {
        return (string) config('remotion.node_binary', 'node');
    }

    public static function chromePath(): ?string
    {
        $configured = config('remotion.chrome_executable');
        if ($configured && is_file(self::absolute((string) $configured))) {
            return self::absolute((string) $configured);
        }

        $root = self::join(self::directory(), 'node_modules/.remotion/chrome-headless-shell');
        $patterns = [
            $root.'/*/*/chrome-headless-shell.exe',
            $root.'/*/*/chrome-headless-shell',
        ];

        foreach (self::globMany($patterns) as $candidate) {
            return $candidate;
        }

        foreach ([
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return self::inPath('chrome') ?? self::inPath('google-chrome') ?? self::inPath('chromium');
    }

    public static function ffprobePath(): ?string
    {
        $configured = config('remotion.ffprobe_path');
        if ($configured && is_file(self::absolute((string) $configured))) {
            return self::absolute((string) $configured);
        }

        $patterns = [
            self::join(self::directory(), 'node_modules/@remotion/compositor-*/ffprobe.exe'),
            self::join(self::directory(), 'node_modules/@remotion/compositor-*/ffprobe'),
        ];

        foreach (self::globMany($patterns) as $candidate) {
            return $candidate;
        }

        return self::inPath('ffprobe');
    }

    public static function ffmpegPath(): ?string
    {
        $configured = config('remotion.ffmpeg_path');
        if ($configured && is_file(self::absolute((string) $configured))) {
            return self::absolute((string) $configured);
        }

        $patterns = [
            self::join(self::directory(), 'node_modules/@remotion/compositor-*/ffmpeg.exe'),
            self::join(self::directory(), 'node_modules/@remotion/compositor-*/ffmpeg'),
        ];

        foreach (self::globMany($patterns) as $candidate) {
            return $candidate;
        }

        return self::inPath('ffmpeg');
    }

    /**
     * Environment variables passed to the Remotion CLI.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $env = [];

        if ($chrome = self::chromePath()) {
            $env['REMOTION_CHROME_EXECUTABLE'] = $chrome;
        }
        if ($ffmpeg = self::ffmpegPath()) {
            $env['FFMPEG_PATH'] = $ffmpeg;
        }
        if ($ffprobe = self::ffprobePath()) {
            $env['FFPROBE_PATH'] = $ffprobe;
        }

        $entryPoint = self::entryPoint();
        if (is_file($entryPoint)) {
            $env['REMOTION_ENTRY_POINT'] = $entryPoint;
        }

        $env['REMOTION_PORT'] = (string) config('remotion.port', 3099);

        return $env;
    }

    public static function absolute(string $path): string
    {
        return self::isAbsolute($path) ? $path : base_path($path);
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private static function join(string $directory, string $path): string
    {
        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    private static function globMany(array $patterns): array
    {
        $matches = [];

        foreach ($patterns as $pattern) {
            $found = glob($pattern);
            if ($found !== false) {
                $matches = array_merge($matches, $found);
            }
        }

        return array_values(array_filter($matches, 'is_file'));
    }

    private static function inPath(string $binary): ?string
    {
        $command = DIRECTORY_SEPARATOR === '\\' ? ['where', $binary] : ['which', $binary];

        try {
            $process = new Process($command);
            $process->run();

            if ($process->isSuccessful()) {
                $first = strtok(trim($process->getOutput()), "\r\n");
                if ($first && is_file($first)) {
                    return $first;
                }
            }
        } catch (\Throwable) {
            // Binary lookup is best-effort; callers report a clear failure.
        }

        return null;
    }
}
