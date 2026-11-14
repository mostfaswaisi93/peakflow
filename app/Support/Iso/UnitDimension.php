<?php

declare(strict_types=1);

namespace App\Support\Iso;

/**
 * What a unit measures.
 *
 * Exists so that a conversion or a comparison between incompatible units can
 * be refused rather than producing a number. Adding 3 hours to 2 kilograms is
 * not a rounding problem.
 */
enum UnitDimension: string
{
    case Count = 'count';
    case Mass = 'mass';
    case Volume = 'volume';
    case Length = 'length';
    case Area = 'area';
    case Time = 'time';
    case Energy = 'energy';
    case Data = 'data';
    case Proportion = 'proportion';
}
