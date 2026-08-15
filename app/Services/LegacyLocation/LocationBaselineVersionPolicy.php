<?php
declare(strict_types=1);

namespace App\Services\LegacyLocation;

/** Classifies an effective-dated history without changing any record. */
final class LocationBaselineVersionPolicy
{
    public static function classify(array $versions):array
    {
        usort($versions,static fn(array $a,array $b):int=>[
            (string)$a['effective_from'],(string)($a['created_at']??''),(string)$a['id'],
        ]<=>[
            (string)$b['effective_from'],(string)($b['created_at']??''),(string)$b['id'],
        ]);
        $first=$versions[0]??null;
        $later=array_slice($versions,1);
        $ambiguous=false;
        if($first!==null&&isset($later[0])){
            $second=$later[0];
            $ambiguous=$second['effective_from']===$first['effective_from']
                || $first['effective_to']===null
                || $first['effective_to']>=$second['effective_from'];
        }
        return ['first'=>$first,'later'=>$later,'ambiguous'=>$ambiguous];
    }
}
