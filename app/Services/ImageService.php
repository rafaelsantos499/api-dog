<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageService
{
    protected ImageManager $manager;

    protected array $variants = [
        'original' => ['method' => 'resizeDown', 'width' => 3000, 'quality' => 85],
        'feed'     => ['method' => 'resizeDown', 'width' => 1024, 'quality' => 80],
        'thumb'    => ['method' => 'cover', 'size' => [300, 300], 'quality' => 75],
    ];

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Create image variants and return an array with binary contents keyed by variant name.
     * Returned values are strings (encoded image data).
     *
     * @param UploadedFile $uploaded
     * @param string $extFilename Filename (used for path decisions if needed)
     * @return array<string,string>
     */
    public function makeVariants(UploadedFile $uploaded, string $extFilename): array
    {
        $results = [];

        foreach ($this->variants as $name => $cfg) {
            
            $img = $this->manager->read($uploaded)->orient();

            if ($cfg['method'] === 'resizeDown') {
                $encoded = $img->resizeDown($cfg['width'], null)->toWebp($cfg['quality']);
            } elseif ($cfg['method'] === 'cover') {
                [$w, $h] = $cfg['size'];
                $encoded = $img->cover($w, $h)->toWebp($cfg['quality']);
            } else {
               
                $encoded = $img->toWebp($cfg['quality'] ?? 80);
            }
          
            $results[$name] = (string) $encoded;
        }

        return $results;
    }
}
