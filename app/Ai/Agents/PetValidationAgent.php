<?php

namespace App\Ai\Agents;

use App\Prompts\PetValidationPrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class PetValidationAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Instruções do sistema para o agente.
     * O conteúdo vem de PetValidationPrompt — edite lá para mudar o comportamento.
     */
    public function instructions(): Stringable|string
    {
        return PetValidationPrompt::get();
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Saída estruturada: o modelo retorna { "valid": bool, "reason": string }
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'valid'  => $schema->boolean()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
