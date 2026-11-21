<?php
// Dashboard data is stored in JSON. No database connection is required.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
const DASH_DATA = __DIR__ . '/storage/data';
const DASH_STUBS = __DIR__ . '/storage/broilers';
function dashInit(): void {
    foreach ([DASH_DATA, DASH_STUBS, DASH_DATA . '/snapshots'] as $d) if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('Cannot create ' . $d);
    foreach (['settings.json'=>'{"theme":"light","default_branch":"main","provider":"github"}', 'projects.json'=>'{}'] as $f=>$v) if (!is_file(DASH_DATA.'/'.$f)) file_put_contents(DASH_DATA.'/'.$f,$v,LOCK_EX);
    $defaults=['README.md.stub'=>"# {{PROJECT_NAME}}\n\nA PHP project created with XAMPP Dashboard.\n",'.gitignore.stub'=>"/vendor/\n/node_modules/\n.env\n.idea/\n.vscode/\n",'CHANGELOG.md.stub'=>"# Changelog\n\n## 0.1.0 - {{YEAR}}\n- Initial release of {{PROJECT_NAME}}\n",'SECURITY.md.stub'=>"# Security Policy\n\nReport vulnerabilities privately to the project maintainer. Do not post secrets in public issues.\n",'LICENSE.stub'=>"MIT License\n\nCopyright (c) {{YEAR}} {{PROJECT_NAME}}\n\nPermission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the \"Software\"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.\n"];
    foreach ($defaults as $f=>$v) if (!is_file(DASH_STUBS.'/'.$f) || filesize(DASH_STUBS.'/'.$f) === 0) file_put_contents(DASH_STUBS.'/'.$f,$v,LOCK_EX);
    if (empty($_SESSION['dashboard_csrf'])) $_SESSION['dashboard_csrf']=bin2hex(random_bytes(32));
}
function dashJson(string $name): array { if (!in_array($name,['settings','projects'],true)) throw new InvalidArgumentException('Bad store'); $v=json_decode((string)@file_get_contents(DASH_DATA.'/'.$name.'.json'),true); return is_array($v)?$v:[]; }
function dashSave(string $name,array $data): void { if (!in_array($name,['settings','projects'],true)) throw new InvalidArgumentException('Bad store'); $path=DASH_DATA.'/'.$name.'.json'; $tmp=tempnam(DASH_DATA,'json_'); if ($tmp===false) throw new RuntimeException('Cannot write JSON'); if (file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false || !rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Cannot save JSON'); } }
function dashCsrf(): void { if (!hash_equals((string)($_SESSION['dashboard_csrf']??''),(string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Refresh the page.'); }
function dashName(string $name): bool { return (bool)preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\z/D',$name) && !in_array(strtolower($name),['dashboard','phpmyadmin','xampp','webalizer'],true); }
function dashProject(string $name,bool $existing=true): string { $base=realpath('C:/xampp/htdocs'); if (!$base || !dashName($name)) throw new RuntimeException('Invalid project name'); $candidate=$base.DIRECTORY_SEPARATOR.$name; if ($existing) { $resolved=realpath($candidate); if (!$resolved || realpath(dirname($resolved))!==$base || is_link($candidate) || !is_dir($resolved)) throw new RuntimeException('Project is missing or outside htdocs'); return $resolved; } if (file_exists($candidate) || is_link($candidate)) throw new RuntimeException('Project already exists'); return $candidate; }
function dashRemote(string $url): bool { $p=parse_url($url); return is_array($p) && ($p['scheme']??'')==='https' && !empty($p['host']) && empty($p['user']) && empty($p['pass']) && !preg_match('/[\r\n]/',$url); }
function dashExec(string $command): string { if (!function_exists('exec')) throw new RuntimeException('Command execution is disabled in PHP'); $out=[]; $status=1; @exec($command.' 2>&1',$out,$status); if ($status!==0) throw new RuntimeException('Command failed (exit '.$status.'). Check Git/Composer installation, credentials, or project state.'); return implode("\n",array_slice($out,0,15)); }
function dashStub(string $name,string $project): string { $allowed=['README.md','LICENSE','.gitignore','CHANGELOG.md','SECURITY.md']; if (!in_array($name,$allowed,true)) throw new RuntimeException('Invalid stub'); return str_replace(['{{PROJECT_NAME}}','{{YEAR}}'],[$project,date('Y')],(string)file_get_contents(DASH_STUBS.'/'.$name.'.stub')); }
function dashMeta(string $project,array $changes): void { $data=dashJson('projects'); $data[$project]=array_merge($data[$project]??[], $changes, ['updated_at'=>date(DATE_ATOM)]); dashSave('projects',$data); }
function dashNotice(string $message,bool $error=false): void { $_SESSION[$error?'dashboard_flash_error':'dashboard_flash_success']=$message; }
function dashRedirect(string $page='index.php'): never { header('Location: '.$page,true,303); exit; }

function dashBytes(int $bytes): string { $units=['B','KiB','MiB','GiB','TiB']; $i=0; $n=(float)$bytes; while($n>=1024 && $i<count($units)-1){$n/=1024;$i++;} return number_format($n,$i?1:0).' '.$units[$i]; }
function dashFolderBytes(string $root): ?int {
 try { $sum=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $file){if($file->isLink())continue;if($file->isFile())$sum+=$file->getSize();}return $sum; } catch(Throwable $e){return null;}
}
function dashDbSizes(array $names): array {
 $out=[]; if(!$names || !extension_loaded('pdo_mysql'))return $out;
 $cfg=dashJson('settings');
 try { $pdo=new PDO('mysql:host='.($cfg['db_host']??'127.0.0.1').';port='.(int)($cfg['db_port']??3306).';charset=utf8mb4', (string)($cfg['db_user']??'root'),(string)($cfg['db_password']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
 $st=$pdo->prepare('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = ?');
 foreach($names as $n){if(!preg_match('/^[A-Za-z0-9_$-]{1,64}$/',$n))continue;$st->execute([$n]);$out[$n]=(int)$st->fetchColumn();}
 }catch(Throwable $e){} return $out;
}
function dashDbNames(array $info): array { $value=(string)($info['databases']??$info['database']??'');return array_values(array_unique(array_filter(array_map('trim',explode(',',$value))))); }

dashInit();
