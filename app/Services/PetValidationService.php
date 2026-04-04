<?php

namespace App\Services;

use App\Ai\Agents\PetValidationAgent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;

class PetValidationService
{
    /**
     * Valida se a imagem contém um animal de estimação usando laravel/ai.
     *
     * Tenta o provider primário configurado em config/ai.php (pet_validation.primary).
     * Se falhar, tenta o provider de backup (pet_validation.backup).
     * Se ambos falharem, respeita fail_open para decidir se aprova ou rejeita.
     *
     * @return array{valid: bool, reason: string}
     */
    public function validate(UploadedFile $file): array
    {
        $hash = md5_file($file->getRealPath());

        /** @var array{valid: bool, reason: string} */
        return Cache::remember("ai:pet:{$hash}", now()->addDays(7), function () use ($file) {
            return $this->callProviders($file);
        });
    }

    private function callProviders(UploadedFile $file): array
    {
        $primary   = config('ai.pet_validation.primary');
        $backup    = config('ai.pet_validation.backup');
        $failOpen  = (bool) config('ai.pet_validation.fail_open', true);

        $attachment = Image::fromUpload($file);

        // Tenta provedor primário
        try {
            return $this->invoke($primary, $attachment);
        } catch (\Throwable $e) {
            Log::warning("PetValidationService: primary [{$primary}] failed.", [
                'error' => $e->getMessage(),
            ]);
        }

        // Tenta provedor de backup
        try {
            return $this->invoke($backup, $attachment);
        } catch (\Throwable $e) {
            Log::warning("PetValidationService: backup [{$backup}] failed.", [
                'error' => $e->getMessage(),
            ]);
        }

        Log::error('PetValidationService: all providers failed.', compact('failOpen'));

        return [
            'safe'   => true,
            'valid'  => $failOpen,
            'reason' => 'AI validation unavailable.',
        ];
    }

    /**
     * Invoca o PetValidationAgent no provider especificado com a imagem como attachment.
     *
     * @return array{safe: bool, valid: bool, reason: string}
     */
    private function invoke(string $provider, \Laravel\Ai\Files\Image $attachment): array
    {
        $timeout = (int) config('ai.pet_validation.timeout', 15);

        $response = PetValidationAgent::make()->prompt(
            prompt:      'Analyze the attached image.',
            attachments: [$attachment],
            provider:    $provider,
            timeout:     $timeout,
        );

        // O structured output é retornado como JSON no campo text
        $data = json_decode($response->text, true);

        return [
            'safe'   => (bool) ($data['safe'] ?? true),
            'valid'  => (bool) ($data['valid'] ?? false),
            'reason' => (string) ($data['reason'] ?? ''),
        ];
    }
}
