<?php
require __DIR__.'/dashboard_lib.php';
try {
 $name=(string)($_GET['folder']??'');$path=dashProject($name,true);
 if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension');
 $tmp=tempnam(sys_get_temp_dir(),'dashzip_');if(!$tmp)throw new RuntimeException('Cannot create temporary archive');
 $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot open ZIP');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
 foreach($it as $file){if($file->isLink() || !$file->isFile())continue;$relative=substr($file->getPathname(),strlen($path)+1);$zip->addFile($file->getPathname(),str_replace('\\','/',$relative));}
 $zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);
} catch(Throwable $e){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo 'Backup failed: '.$e->getMessage();}
