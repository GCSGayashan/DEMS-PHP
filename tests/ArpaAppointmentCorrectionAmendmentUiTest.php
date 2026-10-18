<?php
declare(strict_types=1);

if(($argv[1]??'')==='--enabled-child'){
    $_ENV['FEATURE_ARPA_CORRECTION_AMENDMENTS']='true';putenv('FEATURE_ARPA_CORRECTION_AMENDMENTS=true');
}

require dirname(__DIR__).'/bootstrap.php';

use App\Services\{ArpaAppointmentCorrectionAmendmentFeature,ArpaAppointmentCorrectionDecision};

final class ArpaAppointmentCorrectionAmendmentUiTest
{
    private int $assertions=0;

    public function run(array $argv):int
    {
        if(($argv[1]??'')==='--enabled-child')return $this->enabledWithoutSchema();

        $this->same(false,ArpaAppointmentCorrectionAmendmentFeature::enabled(),'feature defaults OFF');
        $uninitialized=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
        $this->same(false,ArpaAppointmentCorrectionAmendmentFeature::schemaAvailable($uninitialized),'feature OFF short-circuits before PDO/schema access');
        $this->enabledChild();
        $this->staticInterface();
        $this->renderNotFound();
        $this->renderForms();
        echo "ArpaAppointmentCorrectionAmendmentUiTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function enabledWithoutSchema():int
    {
        $config=config('database');$name='dems_test_arpa_corr_amendment_ui_'.bin2hex(random_bytes(4));
        if(preg_match('/^dems_test_arpa_corr_amendment_ui_[a-f0-9]{8}$/D',$name)!==1)throw new RuntimeException('Unsafe isolated test database name.');
        $server=new PDO(sprintf('mysql:host=%s;port=%s;charset=%s',$config['host'],$config['port'],$config['charset']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        try{
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['host'],$config['port'],$name,$config['charset']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            if(!ArpaAppointmentCorrectionAmendmentFeature::enabled())throw new RuntimeException('Enabled child did not load enabled feature flag.');
            if(ArpaAppointmentCorrectionAmendmentFeature::schemaAvailable($pdo))throw new RuntimeException('Missing migration schema was reported available.');
            if(ArpaAppointmentCorrectionDecision::effectivePredicate($pdo,'dc')!=='1=1')throw new RuntimeException('Schema-absent effective-decision reads did not preserve legacy behavior.');
            $pdo->exec("CREATE TABLE arpa_appointment_data_correction(id CHAR(36) PRIMARY KEY,supersedes_correction_id CHAR(36) NULL,amendment_request_id CHAR(36) NULL);
                CREATE TABLE arpa_appointment_correction_amendment_request(id CHAR(36) PRIMARY KEY);
                CREATE TABLE arpa_appointment_correction_amendment_event(id BIGINT PRIMARY KEY);");
            if(!ArpaAppointmentCorrectionAmendmentFeature::schemaAvailable($pdo))throw new RuntimeException('Complete amendment schema was reported unavailable.');
            if(!str_contains(ArpaAppointmentCorrectionDecision::effectivePredicate($pdo,'dc'),'supersedes_correction_id'))throw new RuntimeException('Schema-aware effective-decision predicate did not enable lineage.');
            echo "enabled-unavailable-ok\n";return 0;
        }finally{$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
    }

    private function enabledChild():void
    {
        $command=[PHP_BINARY,__FILE__,'--enabled-child'];$pipes=[];
        $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);
        if(!is_resource($process))throw new RuntimeException('Could not start enabled-feature child test.');
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        $this->same(0,$code,'enabled feature child exits successfully: '.$stderr);
        $this->same(true,str_contains((string)$stdout,'enabled-unavailable-ok'),'enabled feature reports missing migration as unavailable');
    }

    private function staticInterface():void
    {
        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentCorrectionAmendmentController.php');
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $profileController=(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');
        $profileView=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');
        $detailView=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/correction_amendments/show.php');
        $tabs=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/tabs.php');
        $registry=(string)file_get_contents(BASE_PATH.'/app/Core/DataTableRegistry.php');
        $feature=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentCorrectionAmendmentFeature.php');
        $directCorrection=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentDataIssueCorrectionService.php');
        $availableStart=(int)strpos($controller,'private function available():bool');
        $availableSection=substr($controller,$availableStart,(int)strpos($controller,'private function renderForm(',$availableStart)-$availableStart);

        foreach(['create','store','show','edit','update','withdraw','submit','review','pending','completed'] as $method)$this->same(true,str_contains($controller,'function '.$method.'('),'controller exposes '.$method.' action');
        $this->same(true,substr_count($controller,'Csrf::validate()')===5,'every mutation validates CSRF');
        $this->same(true,str_contains($controller,'createDraft(')&&str_contains($controller,'updateDraft(')&&str_contains($controller,'->withdraw(')&&str_contains($controller,'->submit(')&&str_contains($controller,'->review('),'thin controller reuses amendment workflow mutations');
        $this->same(false,str_contains($controller,'correctCanonicalEndDate(')||str_contains($controller,'promoteHistoricalAppointment('),'UI controller cannot apply canonical corrections');
        $this->same(true,str_contains($controller,'http_response_code(404)')&&str_contains($controller,"render('partials/not-found')"),'disabled direct routes return unavailable 404');
        $this->same(true,strpos($availableSection,'if(!ArpaAppointmentCorrectionAmendmentFeature::enabled())')<strpos($availableSection,'schemaAvailable('),'disabled direct routes short-circuit before amendment schema access');
        $this->same(true,str_contains($availableSection,"render('partials/not-found');return false;"),'disabled direct routes return immediately after the controlled response');
        $this->same(true,str_contains($controller,'http_response_code(503)')&&str_contains($controller,"correction_amendments/unavailable"),'enabled feature without migration returns controlled 503');
        foreach([['create','store'],['show','edit'],['edit','update']] as [$start,$end]){
            $section=substr($controller,(int)strpos($controller,'public function '.$start.'('),(int)strpos($controller,'public function '.$end.'(')-(int)strpos($controller,'public function '.$start.'('));
            $this->same(false,str_contains($section,'createDraft(')||str_contains($section,'updateDraft(')||str_contains($section,'->withdraw('),'GET '.$start.' does not execute a mutation');
        }
        foreach([
            '/issues/corrections/{sourceCorrectionId}/amendments/new',
            '/issues/corrections/{sourceCorrectionId}/amendments',
            '/correction-amendments/{id}',
            '/correction-amendments/{id}/edit',
            '/correction-amendments/{id}/withdraw',
            '/correction-amendments/{id}/submit',
            '/correction-amendments/{id}/review',
            '/correction-amendments/pending',
            '/correction-amendments/completed',
        ] as $route)$this->same(true,str_contains($routes,$route),'route registered: '.$route);
        $this->same(true,strpos($routes,'correction-amendments/pending')<strpos($routes,'correction-amendments/{id}'),'static list routes precede the generic amendment route');
        $this->same(false,str_contains($routes,'correction-amendments/{id}/apply'),'no direct apply endpoint exists');
        $this->same(true,strpos($feature,'if(!self::enabled())return false;')<strpos($feature,'information_schema.tables'),'feature gate short-circuits before schema inspection');
        $this->same(true,str_contains($profileController,"if(\$correctionAmendmentUi['enabled'])"),'Officer Profile does no amendment work unless feature enabled');
        $this->same(true,str_contains($profileView,'>View</a>'),'existing correction View link is preserved');
        $this->same(true,str_contains($profileView,'Request Correction')&&str_contains($profileView,'View Correction Request'),'profile contains eligible and active-draft entry points');
        $this->same(true,str_contains($profileView,"['REQUEST_ONLY','SOURCE_MISMATCH','SUPERSEDED']"),'profile explains request-only and superseded ineligibility');
        $this->same(true,str_contains($detailView,"['interface_actions']")&&str_contains($detailView,'Complete Affected Appointment Set'),'detail uses server-derived actions and shows the frozen multi-record set');
        $this->same(true,str_contains($detailView,'confirm_apply')&&str_contains($detailView,'Final National approval applies'),'final approval requires an explicit confirmation warning');
        $this->same(true,str_contains($detailView,'Current cycle')&&str_contains($detailView,'Earlier cycle'),'event history distinguishes current and previous submission cycles');
        $this->same(true,str_contains($tabs,'Pending Correction Approvals')&&str_contains($tabs,'My Completed Correction Reviews'),'feature-aware ARPA navigation contains both dedicated lists');
        $this->same(true,str_contains($registry,"'arpa-correction-amendments-pending'")&&str_contains($registry,"'arpa-correction-amendments-completed'"),'dedicated amendment DataTable keys are registered');
        $this->same(true,str_contains($registry,'unavailableCorrectionAmendmentDataTable'),'DataTable definitions fail closed without amendment table access');
        $this->same(false,str_contains($directCorrection,'SELECT c.*'),'existing correction pages do not implicitly select amendment lineage columns');
    }

    private function renderNotFound():void
    {
        ob_start();require BASE_PATH.'/app/Views/partials/not-found.php';$html=(string)ob_get_clean();
        $this->same(true,str_contains($html,'Page Not Found')&&str_contains($html,'requested page is unavailable'),'shared controlled 404 content is rendered');
        foreach(['Fatal error','Stack trace','SQLSTATE','View not found:',BASE_PATH] as $diagnostic){
            $this->same(false,str_contains($html,$diagnostic),'controlled 404 does not expose '.$diagnostic);
        }
    }

    private function renderForms():void
    {
        $_SESSION=[];
        $baseline=[
            'source_correction'=>['issue_type'=>'LEGACY_HISTORICAL_EXCEPTION','correction_action'=>'KEEP_AS_HISTORICAL_EXCEPTION','resolution_status'=>'KEPT_HISTORICAL_EXCEPTION','correction_reason'=>'Original decision','evidence_reference'=>'Legacy register'],
            'appointment'=>['appointment_type'=>'ACTING','effective_from'=>'2025-01-01','hierarchy_snapshot'=>['asc'=>['name_en'=>'ASC One'],'arpa'=>['name_en'=>'Division One']]],
            'closure'=>null,'request'=>['workflow_status'=>'DISTRICT_APPROVED'],
        ];
        $eligibility=['eligible'=>true,'baseline'=>$baseline,'appointment_types'=>['PERMANENT','ACTING'],'arpa_divisions'=>[['id'=>'division-id','dad_number'=>'70007-0000001','name_en'=>'Division One']],'end_reasons'=>[['id'=>'reason-id','name_en'=>'Transfer']]];
        $mode='create';$sourceCorrectionId='source-id';$request=null;$error='Validation <failed>';
        $form=['proposed_action'=>'CORRECT_EFFECTIVE_FROM','effective_from'=>'2025-02-03','effective_to'=>'','appointment_type'=>'','arpa_division_location_id'=>'','end_reason_id'=>'','correction_reason'=>'Entered <reason>','remarks'=>'Entered notes','evidence_reference'=>'Evidence'];
        ob_start();require BASE_PATH.'/app/Views/arpa_appointments/correction_amendments/form.php';$html=(string)ob_get_clean();
        $this->same(true,str_contains($html,'Editing this proposal does not change the appointment.'),'non-operational edit warning is rendered');
        $this->same(true,str_contains($html,'submit it from the proposal detail page'),'draft form directs users to governed submission');
        $this->same(true,str_contains($html,'name="_csrf"'),'draft form contains CSRF token');
        $this->same(true,str_contains($html,'value="2025-02-03"')&&str_contains($html,'Entered &lt;reason&gt;'),'validation values are preserved and escaped');
        foreach(['baseline_json','baseline_hash','workflow_status','applied_correction_id','appointment_id','officer_id','asc_location_id'] as $protected)$this->same(false,str_contains($html,'name="'.$protected.'"'),'protected '.$protected.' is not submitted by form');
        $this->same(false,str_contains($html,'type="submit">Submit')||str_contains($html,'>Approve<')||str_contains($html,'>Verify<')||str_contains($html,'>Apply<'),'draft edit form exposes no review/apply control');
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

try{exit((new ArpaAppointmentCorrectionAmendmentUiTest())->run($argv));}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
