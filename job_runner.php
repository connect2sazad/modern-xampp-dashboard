<?php

function startJobStream(): void {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering','off');@ini_set('zlib.output_compression','0');
    while(ob_get_level()>0)@ob_end_flush();
    @set_time_limit(0);
}

function emitJob(string $type,string $stage,string $message): void {echo json_encode(['type'=>$type,'stage'=>$stage,'message'=>$message],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES)."\n";@flush();}
function runJob(array $command, string $stage, ?string $workingDirectory = null): void {
 emitJob('stage',$stage,'Running '.$stage.'…');
 if(!function_exists('proc_open'))throw new RuntimeException('PHP proc_open is disabled. Enable it to show live installation output.');
 if (DIRECTORY_SEPARATOR === '\\' && $command[0] === 'composer') {
  // Composer Setup installs a batch wrapper beside composer.phar on Windows.
  foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
   $phar = rtrim($directory, '"') . DIRECTORY_SEPARATOR . 'composer.phar';
   if (is_file($phar)) {
    $php = dashPhpCli();
    array_splice($command, 0, 1, [$php, $phar]);
    break;
   }
  }
 }
 putenv('GIT_TERMINAL_PROMPT=0');
 putenv('GCM_INTERACTIVE=never');
 putenv('COMPOSER_NO_INTERACTION=1');
 // Windows PHP does not support stream_select on process pipes.
 $log = tmpfile();
 if ($log === false) throw new RuntimeException('Could not create the job log.');
 $input = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
 $process = @proc_open($command, [0 => ['file', $input, 'r'], 1 => $log, 2 => ['redirect', 1]], $pipes, $workingDirectory);
 if (!is_resource($process)) {
  fclose($log);
  throw new RuntimeException('Could not start executable. Check Git/Composer PATH for Apache.');
 }
 $reader = fopen(stream_get_meta_data($log)['uri'], 'rb');
 $deadline = time() + 900;
 $last = '';
 $pending = '';
 try {
  if ($reader === false) throw new RuntimeException('Could not read the job log.');
  $code = null;
  while (true) {
   if ($code === null) {
    $status = proc_get_status($process);
    if (!$status['running']) $code = $status['exitcode'];
   }
   // Seeking clears the EOF indicator when the child appends more data.
   fseek($reader, ftell($reader));
   $pending .= fread($reader, 8192);
   $lines = preg_split('/[\r\n]+/', $pending);
   $pending = array_pop($lines);
   if (!$status['running'] && feof($reader)) {
    $lines[] = $pending;
   } elseif (strlen($pending) > 8192) {
    $lines[] = $pending;
    $pending = '';
   }
   foreach ($lines as $line) {
    $line = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', trim($line));
    if ($line === '') continue;
    $last = $line;
    emitJob('output', $stage, substr($line, 0, 700));
   }
   if (!$status['running'] && feof($reader)) {
    break;
   }
   if (time() > $deadline) throw new RuntimeException($stage . ' timed out after 15 minutes');
   if (connection_aborted()) throw new RuntimeException('Client disconnected');
   if ($status['running']) usleep(150000);
  }
 } finally {
  proc_terminate($process);
  proc_close($process);
  if (is_resource($reader)) fclose($reader);
  fclose($log);
 }
 if ($code !== 0) throw new RuntimeException($stage . ' failed (exit ' . $code . '). ' . substr($last, 0, 240));
}
