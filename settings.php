<?php
require __DIR__.'/dashboard_lib.php';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  dashCsrf(); $action=$_POST['action']??'';
  if ($action==='settings') {
   $settings=dashJson('settings');
   $settings['theme']=in_array($_POST['theme']??'',['light','dark'],true)?$_POST['theme']:'light';
   $settings['default_branch']=in_array($_POST['default_branch']??'',['main','master'],true)?$_POST['default_branch']:'main';
   $settings['provider']=in_array($_POST['provider']??'',['github','gitlab','bitbucket','other'],true)?$_POST['provider']:'github';
   dashSave('settings',$settings);
  } elseif ($action==='stub') {
   $stub=(string)($_POST['stub']??'');
   if (!in_array($stub,['README.md','.gitignore','LICENSE','CHANGELOG.md','SECURITY.md'],true)) throw new RuntimeException('Invalid stub');
   $value=(string)($_POST['content']??''); if (strlen($value)>100000) throw new RuntimeException('Stub too large');
   if (file_put_contents(DASH_STUBS.'/'.$stub.'.stub',$value,LOCK_EX)===false) throw new RuntimeException('Could not save stub');
  } else throw new RuntimeException('Unknown action');
  dashNotice('Settings saved'); dashRedirect('settings.php');
 } catch(Throwable $e){$error=$e->getMessage();}
}
$settings=dashJson('settings');
function esc($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en" data-bs-theme="<?=esc($settings['theme']??'light')?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard Settings</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"></head><body><main class="container py-4" style="max-width:1000px"><div class="d-flex justify-content-between"><h3>⚙️ Dashboard Settings</h3><a class="btn btn-outline-secondary" href="index.php">Back to Dashboard</a></div><?php if($error):?><div class="alert alert-danger"><?=esc($error)?></div><?php endif;?><?php if(isset($_SESSION['dashboard_flash_success'])):?><div class="alert alert-success"><?=esc($_SESSION['dashboard_flash_success'])?></div><?php unset($_SESSION['dashboard_flash_success']);endif;?><div class="card my-3"><div class="card-body"><h5>Preferences</h5><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><input type="hidden" name="action" value="settings"><div class="row g-3"><div class="col-md-4"><label class="form-label">Theme</label><select name="theme" class="form-select"><?php foreach(['light','dark'] as $v):?><option value="<?=$v?>" <?=($settings['theme']??'light')===$v?'selected':''?>><?=ucfirst($v)?></option><?php endforeach;?></select></div><div class="col-md-4"><label class="form-label">Default Git branch</label><select name="default_branch" class="form-select"><?php foreach(['main','master'] as $v):?><option value="<?=$v?>" <?=($settings['default_branch']??'main')===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div class="col-md-4"><label class="form-label">Git hosting preference</label><select name="provider" class="form-select"><?php foreach(['github','gitlab','bitbucket','other'] as $v):?><option value="<?=$v?>" <?=($settings['provider']??'github')===$v?'selected':''?>><?=ucfirst($v)?></option><?php endforeach;?></select></div></div><p class="form-text">Git credentials are managed by Git Credential Manager, not stored in JSON.</p><button class="btn btn-primary">Save preferences</button></form></div></div><h4>Starter file templates</h4><p class="text-muted">Use <code>{{PROJECT_NAME}}</code> and <code>{{YEAR}}</code> as placeholders. Changes apply to new projects only.</p><?php foreach(['README.md','.gitignore','LICENSE','CHANGELOG.md','SECURITY.md'] as $stub):?><div class="card mb-3"><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['dashboard_csrf'])?>"><input type="hidden" name="action" value="stub"><input type="hidden" name="stub" value="<?=esc($stub)?>"><label class="form-label fw-bold"><?=esc($stub)?> stub</label><textarea name="content" class="form-control font-monospace" rows="7" spellcheck="false"><?=esc(file_get_contents(DASH_STUBS.'/'.$stub.'.stub'))?></textarea><button class="btn btn-outline-primary mt-2">Save <?=esc($stub)?></button></form></div></div><?php endforeach;?></main></body></html>
