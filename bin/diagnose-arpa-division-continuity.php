<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\ArpaDivisionContinuityService;

require dirname(__DIR__).'/bootstrap.php';

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"This diagnostic is CLI-only.\n");
    exit(1);
}

try{
    $rows=(new ArpaDivisionContinuityService(Database::pdo()))->invalidPendingAssignments();
    echo "ARPA Division Continuity Diagnostic (read-only)\n";
    echo "Invalid submitted/pending native assignments: ".count($rows)."\n\n";
    foreach($rows as $row){
        echo implode(' | ',[
            (string)$row['division_dad_number'].' '.(string)$row['division_name'],
            'Last covered: '.($row['last_covered_through']?:'None from 2025-01-01'),
            'Required: '.(string)$row['required_next_start'],
            'Submitted: '.(string)$row['requested_effective_from'],
            'Gap: '.(string)$row['gap_start'].' to '.(string)$row['gap_end'],
            (string)$row['officer_dad_number'].' '.(string)$row['officer_name'],
            'Request: '.(string)$row['id'],
            'Status: '.(string)$row['workflow_status'],
            'Overlapping unresolved Data Issue: '.($row['unresolved_data_issue']?'YES':'NO'),
        ])."\n";
    }
    exit(0);
}catch(Throwable $exception){
    fwrite(STDERR,'Diagnostic failed: '.$exception->getMessage()."\n");
    exit(1);
}
