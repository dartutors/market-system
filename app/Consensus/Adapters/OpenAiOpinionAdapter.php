<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

final class OpenAiOpinionAdapter extends AbstractAiOpinionAdapter
{
    protected function providerName(): string { return 'openai'; }
    protected function sourceLabel(): string { return 'OpenAI'; }
}
