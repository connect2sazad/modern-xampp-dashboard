<?php
/** Stream real Composer/Git output as NDJSON; no fabricated completion percentage. */
require_once __DIR__.'/mxdp_lib.php';
require_once __DIR__ . '/template_lib.php';
require_once __DIR__ . '/job_runner.php';
startJobStream();

try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required');
 dashCsrf();$action=(string)($_POST['action']??'');$name=trim((string)($_POST['project']??''));
 if(!in_array($action,['clone','framework','template'],true))throw new RuntimeException('Invalid action');
 $dest=dashProject($name,false);
 if($action==='clone'){
  $remote=trim((string)($_POST['remote']??''));if(!dashRemote($remote))throw new RuntimeException('A valid HTTPS Git URL is required');
  $branch=trim((string)($_POST['branch']??''));if($branch!==''&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,100}$/',$branch))throw new RuntimeException('Invalid branch name');
  $args=['git','clone','--progress'];if($branch!=='')array_push($args,'--branch',$branch);array_push($args,'--',$remote,$dest);
  runJob($args,'Cloning repository');dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'clone','remote'=>$remote]);
  dashQueueDatabaseSetup($name);
 } elseif ($action === 'template') {
  $id = $_POST['template'] ?? '';
  $template = is_string($id) ? (dashTemplates()[$id] ?? null) : null;
  if ($template === null) throw new RuntimeException('Unknown project template.');
  dashInstallTemplate($template, $dest);
  dashConfigurePublicDirectory($dest, $template['directory']);
  dashMeta($name, ['created_at' => date(DATE_ATOM), 'kind' => $template['category'], 'template' => [
      'label' => $template['label'], 'directory' => $template['directory'],
      'method' => $template['method'], 'source' => $template['source'],
  ]]);
  if (dashWebEntry($dest, '', $template['directory']) === null) {
      emitJob('output', 'Setup', 'No web entry point found in the configured public folder. Check the template setup instructions.');
  } else {
      emitJob('output', 'Setup', 'Use Open on the project card to finish setup.');
  }
 }else{
  $framework=(string)($_POST['framework']??'');if(!isset(DASH_FRAMEWORKS[$framework]))throw new RuntimeException('Unsupported framework');
  $branch=(string)($_POST['branch']??'main');if(!in_array($branch,['main','master'],true))throw new RuntimeException('Invalid branch');
  $remote=trim((string)($_POST['remote']??''));if($remote!==''&&!dashRemote($remote))throw new RuntimeException('Invalid HTTPS Git remote URL');
  $init=!empty($_POST['git_init']);$push=!empty($_POST['git_push']);
  if($remote!==''&&!$init)throw new RuntimeException('Enable Git initialization to add a remote');
  if($push&&(!$init||$remote===''))throw new RuntimeException('Enable Git and supply a remote URL to push');
  runJob(['composer','create-project','--no-interaction','--prefer-dist',DASH_FRAMEWORKS[$framework]['package'],$dest],'Installing '.$framework.' with Composer');
  if (dashConfigureFrameworkWebRoot($dest, $framework)) {
   emitJob('output', 'Apache', 'Configured the framework public directory.');
  } elseif (dashWebEntry($dest, $framework) === null) {
   emitJob('output', 'Apache', 'The template has no public entry point yet. Open Developer on the project card for setup details.');
  }
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
