<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/** Live schema evidence only. Never replaces the application's data connection. */
final class ArpaHistoricalApprovalSchemaVerifier
{
    private const TABLES=['arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event'];
    private const TRIGGERS=['trg_hist_approval_event_no_update'=>'UPDATE','trg_hist_approval_event_no_delete'=>'DELETE'];

    public function __construct(private readonly ?PDO $metadata=null){}

    /** Credentials are read as data, never included in arguments, environment or exceptions. */
    public static function fromProtectedConfig(string $path):self
    {
        self::cli();
        $handle=null;
        try{
            $real=realpath($path);
            if($real===false||is_link($path)||!is_file($real))throw new DomainException('Schema verifier configuration is unavailable or unsafe.',503);
            // Conservatively exclude the entire deployment parent, not just /public.
            foreach([BASE_PATH,dirname(BASE_PATH),$_SERVER['DOCUMENT_ROOT']??''] as $root){
                $root=$root===''?false:realpath($root);
                if($root!==false&&self::within($real,$root))throw new DomainException('Schema verifier configuration must be outside the deployment and web roots.',503);
            }
            $handle=@fopen($real,'rb');
            if($handle===false)throw new DomainException('Schema verifier configuration is unreadable.',503);
            $stat=fstat($handle);
            if(!$stat||$stat['size']>16384||($stat['mode']&0170000)!==0100000)throw new DomainException('Schema verifier configuration is unsafe.',503);
            if(PHP_OS_FAMILY==='Windows')self::windowsAcl($real);
            elseif(!function_exists('posix_geteuid')||$stat['uid']!==posix_geteuid()||($stat['mode']&0077)!==0)throw new DomainException('Schema verifier configuration must be private to its CLI owner.',503);
            $config=json_decode(stream_get_contents($handle,16385),true,16,JSON_THROW_ON_ERROR);
            $keys=['host','port','database','username','password'];
            if(!is_array($config)||array_diff(array_keys($config),[...$keys,'ssl_ca'])!==[]||array_diff($keys,array_keys($config))!==[])throw new DomainException('Invalid schema verifier configuration.',503);
            foreach($keys as $key)if(!is_string($config[$key])&&!($key==='port'&&is_int($config[$key])))throw new DomainException('Invalid schema verifier configuration.',503);
            if(!preg_match('/^[a-zA-Z0-9.:-]+$/D',$config['host'])||!preg_match('/^[a-zA-Z0-9_]+$/D',$config['database'])
                ||!preg_match('/^[0-9]{1,5}$/D',(string)$config['port'])||(int)$config['port']<1||(int)$config['port']>65535
                ||$config['username']===''||str_contains($config['username'],"\0")||str_contains($config['password'],"\0"))throw new DomainException('Invalid schema verifier configuration.',503);
            $options=[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,
            ];
            if(isset($config['ssl_ca'])){
                if(!is_string($config['ssl_ca'])||!is_file($config['ssl_ca']))throw new DomainException('A valid verifier TLS CA file is required.',503);
                $options[PDO::MYSQL_ATTR_SSL_CA]=$config['ssl_ca'];$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;
            }elseif(!in_array(strtolower($config['host']),['localhost','127.0.0.1','::1'],true))throw new DomainException('Remote schema verification requires certificate-verified TLS.',503);
            $pdo=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],$options);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY');
            $pdo->exec('SET SESSION lock_wait_timeout=3');
            return new self($pdo);
        }catch(DomainException $e){throw $e;}
        catch(Throwable){throw new DomainException('Schema verifier configuration or connection is unavailable. No registration was performed.',503);}
        finally{if(is_resource($handle))fclose($handle);}
    }

    /** Caller must retain the application transaction until its reads/writes finish. */
    public function verify(PDO $application,bool $forApply=false):array
    {
        self::cli();
        if(!$application->inTransaction())throw new DomainException('Schema verification requires an application transaction.',503);
        try{
            // Real table accesses acquire transaction-duration metadata locks. No LOCK TABLES/implicit commit.
            // During apply acquire SHARED_WRITE MDL up front, avoiding a later
            // read-to-write upgrade behind queued DDL. WHERE 1=0 locks no data rows.
            foreach(self::TABLES as $table){$stmt=$application->query('SELECT id FROM '.$table.($forApply?' WHERE 1=0 FOR UPDATE':' LIMIT 1'));$stmt->fetchColumn();$stmt->closeCursor();}
            $metadata=$this->metadata??$application;
            $identity=$application->query('SELECT DATABASE() database_name,@@server_uuid server_uuid,@@port server_port,@@version mysql_version')->fetch(PDO::FETCH_ASSOC);
            $other=$metadata->query('SELECT DATABASE() database_name,@@server_uuid server_uuid,@@port server_port')->fetch(PDO::FETCH_ASSOC);
            foreach(['database_name','server_uuid','server_port'] as $key)if(!isset($identity[$key],$other[$key])||(string)$identity[$key]===''||(string)$identity[$key]!== (string)$other[$key])throw new DomainException('Schema verifier database/server identity does not match the application.',503);
            $s=$metadata->prepare("SELECT TABLE_NAME table_name,ENGINE engine FROM information_schema.tables WHERE table_schema=? AND table_name IN(?,?) ORDER BY table_name");
            $s->execute([$identity['database_name'],...self::TABLES]);$tables=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($tables)!==2||array_column($tables,'table_name')!==self::TABLES)throw new DomainException('Historical approval tables cannot be verified.',503);
            foreach($tables as $table)if($table['engine']!=='InnoDB')throw new DomainException('Historical approval tables must use InnoDB.',503);
            $s=$metadata->prepare("SELECT TRIGGER_NAME trigger_name,EVENT_OBJECT_TABLE subject_table,ACTION_TIMING timing,EVENT_MANIPULATION operation,ACTION_ORIENTATION orientation,ACTION_STATEMENT body,DEFINER definer,SQL_MODE sql_mode,CREATED created_at,CHARACTER_SET_CLIENT client_charset,COLLATION_CONNECTION connection_collation,DATABASE_COLLATION database_collation,ACTION_ORDER action_order
                FROM information_schema.triggers WHERE trigger_schema=? AND trigger_name IN(?,?) ORDER BY trigger_name");
            $s->execute([$identity['database_name'],...array_keys(self::TRIGGERS)]);$triggers=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($triggers)!==2)throw new DomainException('Both append-only trigger definitions must be visible and verifiable; absent metadata is not proof of installation.',503);
            $seen=[];
            foreach($triggers as &$trigger){
                $name=$trigger['trigger_name'];
                if(!isset(self::TRIGGERS[$name])||isset($seen[$name])||$trigger['subject_table']!==self::TABLES[1]||$trigger['timing']!=='BEFORE'||$trigger['operation']!==self::TRIGGERS[$name]||$trigger['orientation']!=='ROW'
                    ||!preg_match('/\A\s*(?i:SIGNAL\s+SQLSTATE)\s+\x2745000\x27\s+(?i:SET\s+MESSAGE_TEXT)\s*=\s*\x27Historical approval events are append-only\x27\s*;?\s*\z/D',$trigger['body']))throw new DomainException('Historical approval append-only trigger definition differs from migration 067.',503);
                $seen[$name]=true;
                // Only SQL keyword case/spacing outside literals and a trailing delimiter are normalized.
                $trigger['body']="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only'";
            }
            unset($trigger);
            $fingerprint=hash('sha256',json_encode(['identity'=>$other,'tables'=>$tables,'triggers'=>$triggers],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return ['identity'=>$identity,'fingerprint'=>$fingerprint];
        }catch(DomainException $e){throw $e;}
        catch(Throwable){throw new DomainException('Historical approval schema could not be verified. No registration was performed.',503);}
    }

    public function __debugInfo():array{return ['purpose'=>'CLI schema verification; connection details withheld'];}
    private static function cli():void{if(PHP_SAPI!=='cli')throw new DomainException('Schema verification is available only to trusted CLI operators.',403);}
    private static function within(string $path,string $root):bool
    {
        $path=str_replace('\\','/',$path);$root=rtrim(str_replace('\\','/',$root),'/');
        if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$root=strtolower($root);}
        return $path===$root||str_starts_with($path,$root.'/');
    }
    private static function windowsAcl(string $path):void
    {
        // Windows chmod/fileperms do not prove confidentiality. Inspect actual owner and inherited/explicit ACLs.
        $encoded=base64_encode($path);
        $script=<<<'PS'
$ErrorActionPreference='Stop'
try {
  $p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__PATH__'))
  $acl=[IO.File]::GetAccessControl($p)
  $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  $allowed=@($sid,'S-1-5-18','S-1-5-32-544')
  if($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $allowed){exit 1}
  foreach($rule in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])) {
    if($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin $allowed){exit 1}
  }
  exit 0
} catch {exit 1}
PS;
        $pipes=[];$process=@proc_open(['powershell.exe','-NoProfile','-NonInteractive','-Command','-'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new DomainException('Cannot verify schema verifier file permissions.',503);
        fwrite($pipes[0],str_replace('__PATH__',$encoded,$script)."\n\n");fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new DomainException('Schema verifier configuration ACL is not private to trusted CLI administrators.',503);
    }
}
