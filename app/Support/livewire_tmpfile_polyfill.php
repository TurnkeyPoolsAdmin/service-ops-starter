<?php

/**
 * Cloudways (and some hardened PHP-FPM pools) disable the global tmpfile()
 * function. Livewire's TemporaryUploadedFile calls unqualified tmpfile() from
 * inside its namespace, which then resolves as
 * Livewire\Features\SupportFileUploads\tmpfile and fatals with
 * "Call to undefined function …\tmpfile()" — Filament FileUpload hangs at 99%.
 *
 * This polyfill defines a namespaced tmpfile() that opens a real temp file under
 * storage/app/livewire-tmp (same directory Livewire already uses). Loaded early
 * from AppServiceProvider::register().
 */
namespace Livewire\Features\SupportFileUploads;

if (! \function_exists(__NAMESPACE__.'\\tmpfile')) {
    function tmpfile()
    {
        $dir = storage_path('app/livewire-tmp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.uniqid('lw_tmp_', true);
        $handle = @fopen($path, 'w+b');

        if ($handle === false) {
            throw new \RuntimeException('Unable to create a temporary file for uploads at '.$path);
        }

        // Best-effort cleanup when the request ends (tmpfile() auto-unlinks).
        register_shutdown_function(static function () use ($path, &$handle): void {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            @unlink($path);
        });

        return $handle;
    }
}
