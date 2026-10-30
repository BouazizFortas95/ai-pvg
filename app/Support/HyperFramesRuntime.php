<?php

namespace App\Support;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Resolves HyperFrames paths and checks the local toolchain without making
 * any network call. All render/CLI execution goes through Symfony Process
 * argument arrays (never shell strings).
 */
class HyperFramesRuntime
{
    public static function directory(): string
    {
        $configured = trim((string) config('automation.render.hyperframes.directory', ''));

        return $configured !== '' ? self::absolute($configured) : base_path('hyperframes');
    }

    public static function outputRoot(): string
    {
        $configured = trim((string) config('automation.render.hyperframes.output_dir', ''));

        return $configured !== '' ? self::absolute($configured) : storage_path('app/hyperframes');
    }

    public static function nodeBinary(): string
    {
        $configured = trim((string) config('automation.render.hyperframes.node_binary', 'node'));

        return $configured !== '' ? $configured : 'node';
    }

    /**
     * Path to the pinned HyperFrames CLI entry point, or null when missing.
     */
    public static function cliPath(): ?string
    {
        $configured = trim((string) config('automation.render.hyperframes.cli', ''));

        if ($configured !== '') {
            $candidate = self::absolute($configured);

            return is_file($candidate) ? $candidate : null;
        }

        $candidates = [
            self::join(self::directory(), 'node_modules/hyperframes/bin/hyperframes.mjs'),
            self::join(base_path(), 'node_modules/hyperframes/bin/hyperframes.mjs'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Published installs keep the CLI under the global npm root.
        foreach (self::globalNpmRoots() as $root) {
            $candidate = self::join($root, 'hyperframes/bin/hyperframes.mjs');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function nodePath(): ?string
    {
        $configured = self::nodeBinary();

        if (self::isAbsolute($configured) && is_file($configured)) {
            return $configured;
        }

        return self::inPath($configured);
    }

    /**
     * @return list<string>
     */
    public static function commandPrefix(): array
    {
        $node = self::nodePath();
        $cli = self::cliPath();

        if ($node !== null && $cli !== null) {
            return [$node, $cli];
        }

        return DIRECTORY_SEPARATOR === '\\' ? ['npx.cmd', '--no-install', 'hyperframes'] : ['npx', '--no-install', 'hyperframes'];
    }

    public static function ffprobePath(): ?string
    {
        return RemotionRuntime::ffprobePath();
    }

    /**
     * HyperFrames needs a full FFmpeg build (filters such as setparams).
     * Remotion's bundled FFmpeg is intentionally stripped, so prefer a
     * configured or system-installed full build.
     */
    public static function ffmpegPath(): ?string
    {
        $configured = trim((string) config('automation.render.hyperframes.ffmpeg_path', ''));

        if ($configured !== '') {
            $candidate = self::absolute($configured);

            return is_file($candidate) ? $candidate : null;
        }

        if ($fromPath = self::inPath('ffmpeg')) {
            return $fromPath;
        }

        foreach (self::globMany([
            'C:/Users/*/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_*/ffmpeg-*/bin/ffmpeg.exe',
            'C:/ffmpeg/bin/ffmpeg.exe',
            'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
        ]) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function ffmpegDirectory(): ?string
    {
        $ffmpeg = self::ffmpegPath();

        return $ffmpeg !== null ? dirname($ffmpeg) : null;
    }

    /**
     * HyperFrames requires a full FFmpeg build (the setparams filter is used
     * during assembly). Remotion's bundled FFmpeg is intentionally stripped,
     * so the preflight checks this explicitly instead of trusting any binary.
     */
    public static function ffmpegSupportsSetparams(): bool
    {
        $ffmpeg = self::ffmpegPath();

        if ($ffmpeg === null) {
            return false;
        }

        try {
            $process = new Process([$ffmpeg, '-hide_banner', '-filters'], null, null, null, 20);
            $process->run();

            return $process->isSuccessful() && str_contains($process->getOutput(), 'setparams');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Config switch AND the `render.hyperframes` feature flag, both required.
     * Missing tables fail closed.
     */
    public static function enabled(): bool
    {
        if (! (bool) config('automation.render.hyperframes.enabled', false)) {
            return false;
        }

        return self::flagEnabled((string) config('automation.render.hyperframes.feature_flag', 'render.hyperframes'));
    }

    public static function flagEnabled(string $key): bool
    {
        try {
            if (! Schema::hasTable('feature_flags')) {
                return false;
            }

            return (bool) FeatureFlag::query()->where('key', $key)->value('enabled');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{available: bool, reason: ?string, cli: ?string, node: ?string, ffmpeg: ?string, ffprobe: ?string, directory: string, output: string, enabled: bool, flag: string, flag_enabled: bool}
     */
    public static function status(): array
    {
        $missing = [];
        $cli = self::cliPath();
        $node = self::nodePath();
        $ffmpeg = self::ffmpegPath();
        $ffprobe = self::ffprobePath();
        $directory = self::directory();
        $flag = (string) config('automation.render.hyperframes.feature_flag', 'render.hyperframes');

        if ($node === null) {
            $missing[] = 'Node binary not found: '.self::nodeBinary();
        }
        if ($cli === null) {
            $missing[] = 'HyperFrames CLI not found (run npm install in hyperframes/)';
        }
        if ($ffmpeg === null) {
            $missing[] = 'Full FFmpeg build not found (HyperFrames needs filters such as setparams)';
        }
        if ($ffprobe === null) {
            $missing[] = 'FFprobe not found';
        }
        if (! is_dir($directory)) {
            $missing[] = 'Template directory missing: '.$directory;
        }

        return [
            'available' => $missing === [],
            'reason' => $missing !== [] ? implode('; ', $missing) : null,
            'cli' => $cli,
            'node' => $node,
            'ffmpeg' => $ffmpeg,
            'ffprobe' => $ffprobe,
            'directory' => $directory,
            'output' => self::outputRoot(),
            'enabled' => self::enabled(),
            'flag' => $flag,
            'flag_enabled' => self::flagEnabled($flag),
        ];
    }

    /**
     * Environment additions that make the CLI find Node and FFmpeg.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $env = [];
        $prepend = [];

        if ($node = self::nodePath()) {
            $prepend[] = dirname($node);
            $env['HYPERFRAMES_NODE'] = $node;
        }

        if ($ffmpeg = self::ffmpegDirectory()) {
            $prepend[] = $ffmpeg;
        }

        if ($ffprobe = self::ffprobePath()) {
            $env['FFPROBE_PATH'] = $ffprobe;
            $prepend[] = dirname($ffprobe);
        }

        $currentPath = getenv('PATH') ?: '';
        $env['PATH'] = implode(PATH_SEPARATOR, array_unique($prepend)).($currentPath !== '' ? PATH_SEPARATOR.$currentPath : '');

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

    public static function join(string $directory, string $path): string
    {
        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    /**
     * @return list<string>
     */
    private static function globalNpmRoots(): array
    {
        $roots = [];

        if (DIRECTORY_SEPARATOR === '\\') {
            $appData = getenv('APPDATA');
            if ($appData) {
                $roots[] = $appData.'/npm/node_modules';
            }
        }

        foreach (self::globMany(['/usr/local/lib/node_modules', '/opt/homebrew/lib/node_modules']) as $root) {
            $roots[] = $root;
        }

        return $roots;
    }

    private static function inPath(string $binary): ?string
    {
        $names = DIRECTORY_SEPARATOR === '\\'
            ? [$binary.'.exe', $binary.'.cmd', $binary]
            : [$binary];

        foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '')) as $directory) {
            $directory = trim($directory, "\"'");

            if ($directory === '') {
                continue;
            }

            foreach ($names as $name) {
                $candidate = self::join($directory, $name);
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        // Local project/node installation (npx cache is deliberately excluded:
        // renders must use the pinned local CLI under hyperframes/).
        $local = self::join(base_path(), 'node_modules/.bin/'.$binary);
        foreach (DIRECTORY_SEPARATOR === '\\' ? [$local.'.cmd', $local] : [$local] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return list<string>
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

        return array_values($matches);
    }
}
