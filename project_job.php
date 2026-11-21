<?php
/** Stream real Composer/Git output as NDJSON; no fabricated completion percentage. */
require_once __DIR__.'/dashboard_lib.php';
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
@ini_set('output_buffering','off');@ini_set('zlib.output_compression','0');
while(ob_get_level()>0)@ob_end_flush();
@set_time_limit(0);
function emitJob(string $type,string $stage,string $message): void {echo json_encode(['type'=>$type,'stage'=>$stage,'message'=>$message],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES)."\n";@flush();}
function runJob(array $command, string $stage): void {
 emitJob('stage',$stage,'Running '.$stage.'…');
 if(!function_exists('proc_open'))throw new RuntimeException('PHP proc_open is disabled. Enable it to show live installation output.');
 // Use an argument array to bypass the command shell and prevent injection.
 $args=$command;
 // Inherit Apache's PATH so Git and Composer remain discoverable.
 putenv('GIT_TERMINAL_PROMPT=0');putenv('GCM_INTERACTIVE=never');putenv('COMPOSER_NO_INTERACTION=1');
 $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $process=@proc_open($args,$descriptors,$pipes,null,null);
 if(!is_resource($process))throw new RuntimeException('Could not start executable. Check Git/Composer PATH for Apache.');
 fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 $last='';$deadline=time()+900;
 try {
  while(true){$status=proc_get_status($process);$active=$status['running'];$read=[$pipes[1],$pipes[2]];$write=null;$except=null;
   $count=@stream_select($read,$write,$except,0,250000);
   if($count!==false && $count>0)foreach($read as $stream){$chunk=fread($stream,4096);if($chunk!==false && $chunk!==''){
    $chunk=preg_replace('/\x1b\[[0-9;]*[A-Za-z]/','',$chunk);
    foreach(preg_split('/[\r\n]+/',trim($chunk)) as $line)if($line!==''){emitJob('output',$stage,substr($line,0,700));$last=$line;}
   }}
   if(!$active){$code=$status['exitcode'];break;}
   if(time()>$deadline){proc_terminate($process);throw new RuntimeException($stage.' timed out after 15 minutes');}
   if(connection_aborted()){proc_terminate($process);throw new RuntimeException('Client disconnected');}
  }
 }finally{foreach([1,2] as $i)if(is_resource($pipes[$i]))fclose($pipes[$i]);@proc_close($process);}
 if($code!==0)throw new RuntimeException($stage.' failed (exit '.$code.'). '.substr($last,0,240));
}
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required');
 dashCsrf();$action=(string)($_POST['action']??'');$name=trim((string)($_POST['project']??''));
 if(!in_array($action,['clone','framework'],true))throw new RuntimeException('Invalid action');
 $dest=dashProject($name,false);
 if($action==='clone'){
  $remote=trim((string)($_POST['remote']??''));if(!dashRemote($remote))throw new RuntimeException('A valid HTTPS Git URL is required');
  $branch=trim((string)($_POST['branch']??''));if($branch!==''&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,100}$/',$branch))throw new RuntimeException('Invalid branch name');
  $args=['git','clone','--progress'];if($branch!=='')array_push($args,'--branch',$branch);array_push($args,'--',$remote,$dest);
  runJob($args,'Cloning repository');dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'clone','remote'=>$remote]);
 }else{
  $packages=['laravel'=>'laravel/laravel','yii'=>'yiisoft/yii2-app-basic','cakephp'=>'cakephp/app','symfony'=>'symfony/skeleton','codeigniter'=>'codeigniter4/appstarter'];
  $framework=(string)($_POST['framework']??'');if(!isset($packages[$framework]))throw new RuntimeException('Unsupported framework');
  $branch=(string)($_POST['branch']??'main');if(!in_array($branch,['main','master'],true))throw new RuntimeException('Invalid branch');
  $remote=trim((string)($_POST['remote']??''));if($remote!==''&&!dashRemote($remote))throw new RuntimeException('Invalid HTTPS Git remote URL');
  $init=!empty($_POST['git_init']);$push=!empty($_POST['git_push']);
  if($remote!==''&&!$init)throw new RuntimeException('Enable Git initialization to add a remote');
  if($push&&(!$init||$remote===''))throw new RuntimeException('Enable Git and supply a remote URL to push');
  runJob(['composer','create-project','--no-interaction','--prefer-dist',$packages[$framework],$dest],'Installing '.$framework.' with Composer');
  if($init){
   // Some framework templates already contain Git metadata; check first.
   if(!is_dir($dest.'/.git')&&!is_file($dest.'/.git'))runJob(['git','-C',$dest,'init','-b',$branch],'Initializing Git');
   else runJob(['git','-C',$dest,'branch','-M',$branch],'Setting Git branch');
   if($remote!==''){
    $out=[];$code=1;@exec('git -C '.escapeshellarg($dest).' remote get-url origin 2>&1',$out,$code);
    runJob(['git','-C',$dest,'remote',$code===0?'set-url':'add','origin',$remote],'Configuring Git origin');
   }
   if($push){runJob(['git','-C',$dest,'add','--all'],'Staging files');runJob(['git','-C',$dest,'commit','-m','Initial commit'],'Creating initial commit');runJob(['git','-C',$dest,'push','-u','origin',$branch],'Pushing to origin');}
  }
  dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'framework','framework'=>$framework,'remote'=>$remote]);
 }
 emitJob('done','Complete','Project created: '.$name);
}catch(Throwable $e){emitJob('error','Failed',$e->getMessage());}
