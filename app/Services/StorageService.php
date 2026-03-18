<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    protected string $disk;

    public function __construct(string $disk = 'public')
    {
        $this->disk = $disk;
    }

    /**
     * Put binary content into storage and return the full path used.
     */
    public function put(string $path, string $content): string
    {
        Storage::disk($this->disk)->put($path, $content);

        return $path;
    }

    public function url(string $path): string
    {
        try {
            return Storage::disk($this->disk)->url($path);
        } catch (\Throwable $e) {
            // Some custom disks or adapters may not expose a `url()` helper —
            // fallback to constructing URL from disk config or app.url.
            $diskConfigUrl = config("filesystems.disks.{$this->disk}.url");
            if (!empty($diskConfigUrl)) {
                return rtrim($diskConfigUrl, '/') . '/' . ltrim($path, '/');
            }

            return rtrim(config('app.url'), '/') . '/storage/' . ltrim($path, '/');
        }
    }
}
