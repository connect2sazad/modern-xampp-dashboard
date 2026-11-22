<?php
require __DIR__.'/mxdp_lib.php';
$error='';$result='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  dashCsrf(); $action=(string)($_POST['action']??'');$name=trim((string)($_POST['project']??''));
  if ($action==='clone') {
   $url=trim((string)($_POST['remote']??''));if(!dashRemote($url)) throw new RuntimeException('Only HTTPS repository URLs without embedded credentials are allowed');
   $dest=dashProject($name,false);
   dashExec('git clone -- '.escapeshellarg($url).' '.escapeshellarg($dest));
   dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'clone','remote'=>$url]);
   dashQueueDatabaseSetup($name);
   dashNotice('Repository cloned: ' . $name);
   dashRedirect();
  } elseif ($action==='framework') {
   $framework=(string)($_POST['framework']??'');if(!isset(DASH_FRAMEWORKS[$framework])) throw new RuntimeException('Unknown framework');
   $dest=dashProject($name,false);dashExec('composer create-project --no-interaction '.escapeshellarg(DASH_FRAMEWORKS[$framework]['package']).' '.escapeshellarg($dest));
   dashConfigureFrameworkWebRoot($dest, $framework);
   dashMeta($name,['created_at'=>date(DATE_ATOM),'kind'=>'framework','framework'=>$framework]);
   dashNotice('Framework project created: ' . $name);
   dashRedirect('index.php?q=' . rawurlencode($name));
  } elseif (in_array($action,['fetch','pull','project_settings'],true)) {
   $path=dashProject($name,true);
   if ($action==='fetch' || $action==='pull') {
    if(!is_dir($path.'/.git')) throw new RuntimeException('Project is not a Git repository');
    $cmd='git -C '.escapeshellarg($path).' '.($action==='fetch'?'fetch --all --prune':'pull --ff-only');
    dashExec($cmd);$result=ucfirst($action).' completed for '.$name;
   } else {
    $notes=substr(trim((string)($_POST['notes']??'')),0,3000);$db=substr(trim((string)($_POST['database']??'')),0,100);
    dashMeta($name,['notes'=>$notes,'databases'=>$db]);$result='Project metadata saved';
   }
  } else throw new RuntimeException('Unknown action');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$projects=array_values(array_filter(glob('C:/xampp/htdocs/*')?:[],'is_dir'));
$projects=array_filter($projects,static function ($path) {
 try { dashProject(basename($path)); return true; }
 catch (RuntimeException $e) { return false; }
});
$meta=dashJson('projects');
function esc($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en" <?= dashThemeAttributes() ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project Tools</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="themes.css"></head><body><main class="container py-4"><div class="d-flex justify-content-between"><h3>Project Tools</h3><a class="btn btn-outline-secondary" href="index.php">Back to Dashboard</a></div><?php if($error):?><div class="alert alert-danger mt-3"><?=esc($error)?></div><?php endif;?><?php if($result):?><div class="alert alert-success mt-3"><?=esc($result)?></div><?php endif;?><div class="alert alert-secondary mt-3">Environment: <strong>Git</strong> <?=esc((dashCommandAvailable('git'))?'detected':'not detected')?> · <strong>Composer</strong> <?=esc((dashCommandAvailable('composer'))?'detected':'not detected')?> · <strong>PHP ZIP</strong> <?=class_exists('ZipArchive')?'enabled':'disabled'?></div><p class="text-muted">Git and Composer commands require installation and configuration in the Apache/PHP environment. Remote authentication uses Git Credential Manager; no credentials are stored by this dashboard.</p><div class="row g-3"><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Clone Git repository</h5><form method="post"><input type="hidden" name="action" value="clone"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project folder</label><input name="project" class="form-control mb-2" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}"><label class="form-label">HTTPS Git URL</label><input name="remote" type="url" class="form-control mb-2" required placeholder="https://github.com/owner/repo.git"><button class="btn btn-primary">Clone</button></form></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Create framework project</h5><form method="post"><input type="hidden" name="action" value="framework"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project folder</label><input name="project" class="form-control mb-2" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,79}"><label class="form-label">Composer template</label><select name="framework" class="form-select mb-2"><option value="laravel">Laravel</option><option value="yii">Yii 2 Basic</option><option value="cakephp">CakePHP</option><option value="symfony">Symfony</option><option value="codeigniter">CodeIgniter 4</option></select><button class="btn btn-primary">Create with Composer</button></form></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Restore a backup</h5><a class="btn btn-primary" href="index.php?restore=1">Restore MXDP</a></div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h5>Git</h5><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><label class="form-label">Project</label><select name="project" class="form-select mb-2" required><?php foreach($projects as $p):?><option value="<?=esc(basename($p))?>"><?=esc(basename($p))?></option><?php endforeach;?></select><button name="action" value="fetch" class="btn btn-outline-primary mb-1">Git Fetch</button> <button name="action" value="pull" class="btn btn-outline-primary mb-1" onclick="return confirm('Pull remote changes into the selected project?')">Git Pull (fast-forward)</button></form></div></div></div></div><h4 class="mt-4">Project metadata</h4><div class="row g-3"><?php foreach($projects as $p):$name=basename($p);$info=$meta[$name]??[];?><div class="col-md-6"><div class="card"><div class="card-body"><h6><?=esc($name)?></h6><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><input type="hidden" name="action" value="project_settings"><input type="hidden" name="project" value="<?=esc($name)?>"><label class="form-label">Database name (metadata only)</label><input class="form-control mb-2" name="database" value="<?=esc($info['databases']??$info['database']??'')?>"><label class="form-label">Notes</label><textarea class="form-control mb-2" name="notes" rows="2"><?=esc($info['notes']??'')?></textarea><button class="btn btn-sm btn-outline-primary">Save metadata</button> <a class="btn btn-sm btn-outline-success" href="backup.php?folder=<?=rawurlencode($name)?>">Create MXDP backup</a></form></div></div></div><?php endforeach;?></div></main></body></html>
