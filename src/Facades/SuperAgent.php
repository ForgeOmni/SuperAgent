<?php

namespace SuperAgent\Facades;

use Illuminate\Support\Facades\Facade;
use SuperAgent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;

/**
 * @method static Agent addTool(ToolInterface $tool)
 * @method static Agent withSystemPrompt(string $prompt)
 * @method static Agent withModel(string $model)
 * @method static Agent withMaxTurns(int $maxTurns)
 * @method static Agent withOptions(array $options)
 * @method static AgentResult prompt(string $prompt)
 * @method static \Generator stream(string $prompt)
 * @method static array getMessages()
 * @method static Agent clear()
 * @method static LLMProvider getProvider()
 *
 * @see \SuperAgent\Agent
 */
class SuperAgent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'superagent';
    }
}
