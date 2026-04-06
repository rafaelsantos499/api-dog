<?php

namespace App\Ai\Agents;

use App\Prompts\CommentValidationPrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class CommentValidationAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return CommentValidationPrompt::get();
    }

    /** @return Message[] */
    public function messages(): iterable
    {
        return [];
    }

    /** @return Tool[] */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Saída estruturada: { score: number, blocked: bool, reason: string }
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score'   => $schema->number()->required(),
            'blocked' => $schema->boolean()->required(),
            'reason'  => $schema->string()->required(),
        ];
    }
}
