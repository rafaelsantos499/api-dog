<?php

namespace App\Services;

use App\Ai\Agents\CommentValidationAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CommentValidationService
{
    /**
     * Valida um texto de comentário usando providers configurados em config/ai.php
     * Retorna ['blocked' => bool, 'score' => float, 'reason' => string]
     */
    public function validate(string $body): array
    {
        $hash = sha1($body);

        return Cache::remember("ai:comment:{$hash}", now()->addDays(7), function () use ($body) {
            return $this->callProviders($body);
        });
    }

    private function callProviders(string $body): array
    {
        $primary  = config('ai.comment_validation.primary');
        $backup   = config('ai.comment_validation.backup');
        $failOpen = (bool) config('ai.comment_validation.fail_open', true);

        // Tenta provedor primário
        try {
            return $this->invoke($primary, $body);
        } catch (\Throwable $e) {
            Log::warning("CommentValidationService: primary [{$primary}] failed.", ['error' => $e->getMessage()]);
        }

        // Tenta provedor de backup
        try {
            return $this->invoke($backup, $body);
        } catch (\Throwable $e) {
            Log::warning("CommentValidationService: backup [{$backup}] failed.", ['error' => $e->getMessage()]);
        }

        Log::error('CommentValidationService: all providers failed.', compact('failOpen'));

        return [
            'blocked' => ! $failOpen ? true : false,
            'score'   => $failOpen ? 0.0 : 100.0,
            'reason'  => 'AI validation unavailable.',
        ];
    }

    private function invoke(string $provider, string $body): array
    {
        $timeout = (int) config('ai.comment_validation.timeout', 10);

        $response = CommentValidationAgent::make()->prompt(
            prompt: "Analyze the following comment:\n\n" . $body,
            provider: $provider,
            timeout: $timeout,
        );

        $data = json_decode($response->text, true);

        return [
            'blocked' => (bool) ($data['blocked'] ?? false),
            'score'   => (float) ($data['score'] ?? 0.0),
            'reason'  => (string) ($data['reason'] ?? ''),
        ];
    }
}
