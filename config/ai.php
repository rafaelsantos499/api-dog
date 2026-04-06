<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Provedor padrão para operações de texto (agentes).
    | O pacote usa este valor quando nenhum provider é especificado
    | explicitamente na chamada.
    |
    */

    'default'                  => env('AI_DEFAULT_PROVIDER', 'openai'),
    'default_for_images'       => env('AI_DEFAULT_IMAGE_PROVIDER', 'openai'),
    'default_for_audio'        => 'openai',
    'default_for_transcription'=> 'openai',
    'default_for_embeddings'   => 'openai',
    'default_for_reranking'    => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'redis'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validação de pets (failover)
    |--------------------------------------------------------------------------
    |
    | Define o provider primário e de backup para a validação de imagens.
    | Se o primário falhar/timeout, o Agent será re-invocado no backup.
    |
    | fail_open: true  → aprova o upload se AMBOS os providers falharem
    |            false → rejeita
    |
    */

    'pet_validation' => [
        'enabled'   => (bool) env('AI_PET_VALIDATION_ENABLED', true),
        'primary'   => env('AI_PET_PRIMARY', 'openai'),
        'backup'    => env('AI_PET_BACKUP', 'gemini'),
        'fail_open' => (bool) env('AI_PET_FAIL_OPEN', true),
        'timeout'   => (int) env('AI_PET_TIMEOUT', 15), // segundos por provider
    ],

    /*
    |--------------------------------------------------------------------------
    | Validação de comentários (failover)
    |--------------------------------------------------------------------------
    |
    | Mesma ideia da validação de pets: provedor primário + backup e
    | comportamento `fail_open` quando ambos falharem.
    |
    */
    'comment_validation' => [
        'enabled'   => (bool) env('AI_COMMENT_VALIDATION_ENABLED', true),
        'primary'   => env('AI_COMMENT_PRIMARY', 'openai'),
        'backup'    => env('AI_COMMENT_BACKUP', 'gemini'),
        'fail_open' => (bool) env('AI_COMMENT_FAIL_OPEN', true),
        'timeout'   => (int) env('AI_COMMENT_TIMEOUT', 10), // segundos por provider
        'block_threshold' => (int) env('AI_COMMENT_BLOCK_THRESHOLD', 70), // score(0-100) a partir do qual bloqueamos
        // Rate-limit / timeout after multiple blocked comments
        'window_minutes'   => (int) env('AI_COMMENT_WINDOW_MINUTES', 60),
        'max_blocked'      => (int) env('AI_COMMENT_MAX_BLOCKED', 5),
        'timeout_minutes'  => (int) env('AI_COMMENT_TIMEOUT_MINUTES', 30),
        // Rate-limit for VALID comments (prevent spamming even when allowed)
        'comments_window_minutes' => (int) env('AI_COMMENT_COMMENTS_WINDOW_MINUTES', 60),
        'max_comments' => (int) env('AI_COMMENT_MAX_COMMENTS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Formato exigido pelo pacote laravel/ai: cada entrada precisa de 'driver'.
    | Adicione apenas os providers que for usar; os demais podem ficar sem key.
    |
    */

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'key'    => env('OPENAI_API_KEY'),
            'url'    => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key'    => env('GEMINI_API_KEY'),
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key'    => env('ANTHROPIC_API_KEY'),
        ],

    ],

];
