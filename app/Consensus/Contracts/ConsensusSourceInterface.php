<?php
declare(strict_types=1);
namespace App\Consensus\Contracts;

use App\Consensus\ConsensusVote;

interface ConsensusSourceInterface
{
    /** Never throws — any internal failure must resolve to an $available=false vote, same convention as SignalPreviewService::evaluate(). */
    public function vote(string $symbol, string $label): ConsensusVote;
}
