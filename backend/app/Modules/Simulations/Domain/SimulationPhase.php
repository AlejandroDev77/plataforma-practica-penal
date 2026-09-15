<?php

namespace App\Modules\Simulations\Domain;

enum SimulationPhase: string
{
    case Preparation = 'preparation';
    case OpeningStatements = 'opening_statements';
    case EvidenceProduction = 'evidence_production';
    case ClosingArguments = 'closing_arguments';
    case Deliberation = 'deliberation';
    case Completed = 'completed';
}
