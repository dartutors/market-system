<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

final class ClaudeOpinionAdapter extends AbstractAiOpinionAdapter
{
    protected function providerName(): string { return 'claude'; }
    protected function sourceLabel(): string { return 'Claude'; }
}
