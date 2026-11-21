<?php
require __DIR__.'/dashboard_lib.php';
$error='';$result='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  dashCsrf(); $action=(string)($_POST['action']??'');$name=trim((string)($_POST['project']??''));
  if ($action==='clone') {
   $url=trim((string)($_POST['remote']??''));if(!dashRemote($url)) throw new RuntimeException('Only HTTPS repository URLs without embedded credentials are allowed');
   $dest=dashProject($name,false);
   dashExec('git clone -- '.escapeshellarg($url).' '.escapeshellarg($dest));
   dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'clone','remote'=>$url]);$result='Repository cloned: '.$name;
  } elseif ($action==='framework') {
   $packages=['laravel'=>'laravel/laravel','yii'=>'yiisoft/yii2-app-basic','cakephp'=>'cakephp/app','symfony'=>'symfony/skeleton','codeigniter'=>'codeigniter4/appstarter'];
   $framework=(string)($_POST['framework']??'');if(!isset($packages[$framework])) throw new RuntimeException('Unknown framework');
   $dest=dashProject($name,false);dashExec('composer create-project --no-interaction '.escapeshellarg($packages[$framework]).' '.escapeshellarg($dest));
   dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'framework','framework'=>$framework]);$result='Framework project created: '.$name;
  } elseif (in_array($action,['fetch','pull','snapshot','project_settings'],true)) {
   $path=dashProject($name,true);
   if ($action==='fetch' || $action==='pull') {
    if(!is_dir($path.'/.git')) throw new RuntimeException('Project is not a Git repository');
    $cmd='git -C '.escapeshellarg($path).' '.($action==='fetch'?'fetch --all --prune':'pull --ff-only');
    dashExec($cmd);$result=ucfirst($action).' completed for '.$name;
   } elseif ($action==='snapshot') {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable PHP zip extension');
    $dir=DASH_DATA.'/snapshots/'.$name;if(!is_dir($dir))mkdir($dir,0755,true);
    $file=$dir.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.zip';
    $zip=new ZipArchive();if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create snapshot');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));
    foreach($it as $item){if($item->isLink())continue;$rel=substr($item->getPathname(),strlen($path)+1);if(str_starts_with(str_replace('\\','/',$rel),'.git/'))continue;if($item->isFile())$zip->addFile($item->getPathname(),$rel);}
    $zip->close();$result='Local snapshot saved: '.basename($file);
   } else {
    $notes=substr(trim((string)($_POST['notes']??'')),0,3000);$db=substr(trim((string)($_POST['database']??'')),0,100);
    dashMeta($name,['notes'=>$notes,'database'=>$db]);$result='Project metadata saved';
   }
  } elseif ($action==='restore') {
   if(!class_exists('ZipArchive'))throw new RuntimeException('Enable PHP zip extension');
   if(!isset($_FILES['archive'])||$_FILES['archive']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload failed');
   if($_FILES['archive']['size']>50*1024*1024)throw new RuntimeException('Maximum archive size is 50 MB');
   $dest=dashProject($name,false);$zip=new ZipArchive();if($zip->open($_FILES['archive']['tmp_name'])!==true)throw new RuntimeException('Invalid ZIP archive');
   $count=$zip->numFiles;if($count>10000){$zip->close();throw new RuntimeException('Too many files');}
   $total=0;
   for($i=0;$i<$count;$i++){$st=$zip->statIndex($i);$entry=str_replace('\\','/',$st['name']);$total+=(int)$st['size'];if($total>200*1024*1024 || str_starts_with($entry,'/') || preg_match('~(^|/)\.\.(/|$)|^[A-Za-z]:|[\x00-\x1f]~',$entry) || str_contains($entry,':') || str_starts_with($entry,'.git/')){$zip->close();throw new RuntimeException('Unsafe or oversized ZIP archive');}$mode=(($st['external_attributes']??0)>>16)&0170000;if($mode===0120000){$zip->close();throw new RuntimeException('ZIP links are not allowed');}}
   if(!mkdir($dest,0755))throw new RuntimeException('Cannot create project directory');
   try{for($i=0;$i<$count;$i++){$entry=str_replace('\\','/',$zip->getNameIndex($i));if($entry===''||$entry==='.')continue;$target=$dest.'/'.str_replace('/','/',$entry);if(str_ends_with($entry,'/')){if(!is_dir($target))mkdir($target,0755,true);continue;}$parent=dirname($target);if(!is_dir($parent))mkdir($parent,0755,true);$in=$zip->getStream($zip->getNameIndex($i));if(!$in)throw new RuntimeException('Cannot read ZIP entry');$out=fopen($target,'wb');if(!$out){fclose($in);throw new RuntimeException('Cannot write restored file');}stream_copy_to_stream($in,$out);fclose($in);fclose($out);} }finally{$zip->close();}
   dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'restored']);$result='Restored project '.$name;
  } else throw new RuntimeException('Unknown action');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$projects=array_values(array_filter(glob('C:/xampp/htdocs/*')?:[],'is_dir'));
$projects=array_filter($projects,fn($x)=>dashName(basename($x)));
$meta=dashJson('projects');$settings=dashJson('settings');
function esc($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en" data-bs-theme="<?=esc($settings['theme']??'light')?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project Tools</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"></head><body><main class="container py-4"><div class="d-flex justify-content-between"><h3>Project Tools</h3><a class="btn btn-outline-secondary" href="index.php">Back to Dashboard</a></div><?php if($error):?><div class="alert alert-danger mt-3"><?=esc($error)?></div><?php endif;?><?php if($result):?><div class="alert alert-success mt-3"><?=esc($result)?></div><?php endif;?><div class="alert alert-secondary mt-3">Environment: <strong>Git</strong> <?=esc((function_exists('exec') && trim((string)@shell_exec('git --version 2>&1'))!=='')?'detected':'not detected')?> · <strong>Composer</strong> <?=esc((function_exists('exec') && trim((string)@shell_exec('composer --version 2>&1'))!=='')?'detected':'not detected')?> · <strong>PHP ZIP</strong> <?=class_exists('ZipArchive')?'enabled':'disabled'?></div><p class="text-muted">Git and Composer commands require installation and configuration in the Apache/PHP environment. Remote authentication uses Git Credential Manager; no credentials are stored by this dashboard.</p><div class="row g-3"><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Clone Git repository</h5><form method="post"><input type="hidden" name="action" value="clone"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project folder</label><input name="project" class="form-control mb-2" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}"><label class="form-label">HTTPS Git URL</label><input name="remote" type="url" class="form-control mb-2" required placeholder="https://github.com/owner/repo.git"><button class="btn btn-primary">Clone</button></form></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Create framework project</h5><form method="post"><input type="hidden" name="action" value="framework"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project folder</label><input name="project" class="form-control mb-2" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}"><label class="form-label">Composer template</label><select name="framework" class="form-select mb-2"><option value="laravel">Laravel</option><option value="yii">Yii 2 Basic</option><option value="cakephp">CakePHP</option><option value="symfony">Symfony</option><option value="codeigniter">CodeIgniter 4</option></select><button class="btn btn-primary">Create with Composer</button></form></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Restore ZIP / MXD as new project</h5><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="restore"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">New project folder</label><input name="project" class="form-control mb-2" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}"><input type="file" name="archive" accept=".zip,.mxd" class="form-control mb-2" required><button class="btn btn-primary">Restore ZIP</button></form></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Git / Local snapshots</h5><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project</label><select name="project" class="form-select mb-2" required><?php foreach($projects as $p):?><option value="<?=esc(basename($p))?>"><?=esc(basename($p))?></option><?php endforeach;?></select><button name="action" value="fetch" class="btn btn-outline-primary mb-1">Git Fetch</button> <button name="action" value="pull" class="btn btn-outline-primary mb-1" onclick="return confirm('Pull remote changes into the selected project?')">Git Pull (fast-forward)</button> <button name="action" value="snapshot" class="btn btn-outline-success mb-1">Save Local Snapshot</button></form><p class="small text-muted mt-2">Snapshots exclude .git and are saved under storage/data/snapshots.</p></div></div></div></div><h4 class="mt-4">Project metadata</h4><div class="row g-3"><?php foreach($projects as $p):$name=basename($p);$info=$meta[$name]??[];?><div class="col-md-6"><div class="card"><div class="card-body"><h6><?=esc($name)?></h6><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><input type="hidden" name="action" value="project_settings"><input type="hidden" name="project" value="<?=esc($name)?>"><label class="form-label">Database name (metadata only)</label><input class="form-control mb-2" name="database" value="<?=esc($info['database']??'')?>"><label class="form-label">Notes</label><textarea class="form-control mb-2" name="notes" rows="2"><?=esc($info['notes']??'')?></textarea><button class="btn btn-sm btn-outline-primary">Save metadata</button> <a class="btn btn-sm btn-outline-success" href="backup.php?folder=<?=rawurlencode($name)?>">Download ZIP</a> <a class="btn btn-sm btn-outline-success" href="backup.php?folder=<?=rawurlencode($name)?>&amp;format=mxd">Download MXD</a></form></div></div></div><?php endforeach;?></div></main></body></html>
