<?php
/*PhpDoc:
name: checkjsonptr.php
title: checkjsonptr.php - Vérifie la validité de pointeurs JSON
doc: |
  Vérifie dans un fichier Yaml qu'un pointeur JSON pointe effectivement sur une valeur définie
  L'utilisation de file_exists() ne permet pas de traiter les pointeurs distants http
journal: |
  17/2/2021:
    - création
*/

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// trouve dans le document $yaml les pointeurs JSON et retourne la liste sou la forme [path => pointer]
// où path est le chemin dans $yaml et pointer est la valeur définissant le pointeur
function findJsonPointers(string $path, $yaml, array $keys=[]): array {
  //echo "path=$path, keys=",implode('/', $keys),"\n";
  $jptrPath = $path.($keys ? "#/".implode('/', $keys) : '');
  //echo "jptrPath=$jptrPath\n";
  $jptrs = [];
  if (is_array($yaml)) {
    //echo "yaml est un array\n";
    if (isset($yaml['$ref'])) {
      //echo "$jptrPath est un pointeur: ",Yaml::dump($yaml, 0),"\n";
      $jptrs[$jptrPath] = $yaml;
    }
    else {
      foreach ($yaml as $key => $value) {
        $lkeys = $keys;
        $lkeys[] = $key;
        $jptrs = array_merge($jptrs, findJsonPointers($path, $value, $lkeys));
      }
    }
  }
  return $jptrs;
}

// Teste si le fragment défini par $fragId dans $yaml, fragId ne commence pas par # mais commence par un /
// Si oui retourne ['value'=> value], si non retourne ['error'=> path]
// filepath est utilisé pour fabriquer le message d'erreur
// keys et ckeys sont utilisées pour les appels récursifs,
// keys est la liste des clés restantes à tester, ckeys est la liste des clés testées ok
function deref(string $filePath, string $fragId, array $yaml, ?array $keys=null, array $okkeys=[]): array {
  if ($keys === null) {
    $keys = explode('/', $fragId);
    array_shift($keys);
  }
  //echo "  elt(ref=$ref, keys=",implode('/',$keys),", okkeys=",implode('/',$okkeys),")\n";
  if (!$keys) {
    //echo "ok pour #/",implode('/',$ckeys),"\n";
    return ['value'=> $yaml];
  }
  else {
    $key0 = array_shift($keys);
    $okkeys[] = $key0;
    if (!isset($yaml[$key0])) {
      //echo "  erreur sur #/",implode('/',$okkeys),"\n";
      return ['error'=> "$filePath#/".implode('/', $okkeys)];
    }
    else {
      return deref($filePath, $fragId, $yaml[$key0], $keys, $okkeys);
    }
  }
}

// extrait du $ref le chemin du fichier
/*function filePath(string $ref): string {
  if (($pos = strpos($ref, '#')) === false)
    return $ref;
  else
    return substr($ref, 0, $pos);
}*/

// décompose le chemin en host/path/search/fragId
function decompPath(string $fpath): array {
  //echo "$fpath -> ";
  if ((substr($fpath, 0, 7) == 'http://') || (substr($fpath, 0, 8) == 'https://')) {
    if (!preg_match('!^(https?)://([^/]+)(/[^?#]+)(\?[^#]+)?(#.*)?$!', $fpath, $matches))
      throw new Exception("no match for '$fpath' ligne ".__LINE__);
    return [
      'scheme'=> $matches[1],
      'host'=> $matches[2],
      'path'=> $matches[3],
      'search'=> isset($matches[4]) ? substr($matches[4], 1) : '',
      'fragId'=> isset($matches[5]) ? substr($matches[5], 1) : '',
    ];
  }
  elseif (($spos = strpos($fpath, '?')) !== false) {
    $path = substr($fpath, 0, $spos);
    $fpath = substr($fpath, $spos+1);
    if (($fpos = strpos($fpath, '#')) !== false) {
      $search = substr($fpath, 0, $fpos);
      $fragId = substr($fpath, $fpos+1);
    }
    else {
      $search = $fpath;
      $fragId = '';
    }
    return [
      'scheme'=> '',
      'host'=> '',
      'path'=> $path,
      'search'=> $search,
      'fragId'=> $fragId,
    ];
  }
  elseif (($fpos = strpos($fpath, '#')) !== false) {
    return [
      'scheme'=> '',
      'host'=> '',
      'path'=> substr($fpath, 0, $fpos),
      'search'=> '',
      'fragId'=> substr($fpath, $fpos+1),
    ];
  }
  else {
    return [
      'scheme'=> '',
      'host'=> '',
      'path'=> $fpath,
      'search'=> '',
      'fragId'=> '',
    ];
  }
}
if (0) { // TEST de decompPath
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body><pre>\n";
  print_r(decompPath('http://host.fr/a/b/c?sss#fff'));
  print_r(decompPath('http://host.fr/a/b/c#fff'));
  print_r(decompPath('http://host.fr/a/b/c?sss'));
  print_r(decompPath('http://host.fr/a/b/c#ff?sss'));
  /*print_r(decompPath('/a/b/c?sss#fff'));
  print_r(decompPath('/a/b/c#fff'));
  print_r(decompPath('/a/b/c?sss'));
  print_r(decompPath('/a/b/c'));*/
  print_r(decompPath('a/b/c?sss#fff'));
  print_r(decompPath('a/b/c#fff'));
  print_r(decompPath('a/b/c?sss'));
  print_r(decompPath('a/b/c'));
  /*print_r(decompPath('#fff'));
  print_r(decompPath('?sss#fff'));
  print_r(decompPath('?sss'));
  print_r(decompPath(''));*/
  die();
}

/*function pathType(string $filePath): string {
  if ((substr($localFilePath, 0, 7) == 'http://') || (substr($localFilePath, 0, 8) == 'https://'))
    return 'http';
  elseif (substr($localFilePath, 0, 1)== '/')
    return 'abs';
  elseif (substr($localFilePath, 0, 1) == '#')
    return 'intraFile'; // pointeur dans le même fichier
}*/

/*function buildFilePath(string $localFilePath, string $refFilePath): string {
  echo "buildFilePath(localFilePath=$localFilePath, refFilePath=$refFilePath)\n";
  $res = buildFilePath2($localFilePath, $refFilePath);
  echo "-> $res\n";
  return $res;
}
// pour les fichiers dans le même répertoire que $refFilePath, construit le chemin à partir du répertoire
// de $refFilePath et le nom de fichier de $localFilePath
// Pour les chemins absolus locaux ou en http, retourne localFilePath
function buildFilePath2(string $localFilePath, string $refFilePath): string {
  if ((substr($localFilePath, 0, 1)== '/')
    || (substr($localFilePath, 0, 7) == 'http://') || (substr($localFilePath, 0, 8) == 'https://'))
    return $localFilePath;
  else
    return dirname($refFilePath)."/$localFilePath";
}*/

// Vérifie la validité du pointeur défini dans $filePath au chemin $jPtrPath valant $jsonPtr
function checkJsonPointer(string $filePath, array $yaml, string $jPtrPath, array $jsonPtr, string $options) {
  //echo "checkJsonPointer($filePath, $jPtrPath, ",json_encode($jsonPtr),")\n";
  $dcmp = decompPath($jsonPtr['$ref']);
  if (!$dcmp['host']) { // local à la machine
    if (!$dcmp['path'] && !$dcmp['search']) { // local au même fichier
      if (!$dcmp['fragId'])
        throw new Exception("Erreur pointeur vide");
      else
        $result = deref($filePath, substr($jsonPtr['$ref'], 1), $yaml);
    }
    else { // même machine mais autre fichier
      if (substr($dcmp['path'], 0, 1) <> '/') { // chemin relatif
        //echo "\nchemin relatif:\n";
        $path = dirname($filePath).'/'.$dcmp['path'];
        //echo "path=$path\n\n";
      }
      else
        $path = $dcmp['path'];
      if (!file_exists($path)) {
        echo "KO: pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erronné en $dcmp[path]\n";
        return;
      }
      $yaml = Yaml::parseFile($path);
      $result = deref($path, $dcmp['fragId'], $yaml);
    }
  }
  else { // machine distante
    $path = "$dcmp[scheme]://$dcmp[host]$dcmp[path]$dcmp[search]";
    if (($txt = @file_get_contents($path)) === false) {
      echo "KO: pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erronné en $path\n";
      return;
    }
    $yaml = Yaml::parse($txt);
    $result = deref($path, $dcmp['fragId'], $yaml);
  }
  if (isset($result['error']))
    echo "KO: pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erroné en $result[error]\n";
  elseif ($options == 'showOk')
    echo "Ok: pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est Ok\n";
}

function checkFile(string $filePath, string $options, array $filePathsDone=[]): array {
  $filePathsToCheck = [];
  $yaml = Yaml::parseFile($filePath);
  echo Yaml::dump(['jsonPtrs'=> findJsonPointers($_GET['file'], $yaml)], 3, 2);

  foreach (findJsonPointers($filePath, $yaml) as $jPtrPath => $jsonPtr) {
    checkJsonPointer($filePath, $yaml, $jPtrPath, $jsonPtr, $options);
    /*$destFilePath = filePath($jsonPtr['$ref']);
    if ($destFilePath
      && !isset($filePathsDone[$destFilePath]) 
        && !isset($filePathsToCheck[$destFilePath]) 
          && file_exists($destFilePath))
      $filePathsToCheck[$destFilePath] = 1;*/
  }
  $filePathsDone[$filePath] = 1;
  
  /*foreach (array_keys($filePathsToCheck) as $filePathToCheck) {
    if (!isset($filePathsDone[$filePathToCheck]))
      $filePathsDone = checkFile($filePathToCheck, $filePathsDone);
  }*/
  return $filePathsDone;
}

$file = $_GET['file'] ?? realpath('..');

// navigation dans les répertoires en permettant d'ajouter l'option showOk
if (is_dir($dir = $file)) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body>\n";
  $files = [];
  echo "<ul>\n";
  if ($dh = opendir($dir)) {
    while (($file = readdir($dh)) !== false) {
      $pos = strrpos($file, '.');
      $ext = ($pos !== false) ? substr($file, $pos+1) : null;
      //echo "ext=$ext<br>\n";
      if (in_array($ext, ['yaml','json','geojson']) || is_dir("$dir/$file"))
        $files[$file] = 1;
    }
    closedir($dh);
    ksort($files);
    foreach (array_keys($files) as $file) {
      echo "<li><a href='?file=",realpath("$dir/$file"),
        (isset($_GET['options']) ? "&amp;options=$_GET[options]" : ''),
        "'>$file</a></li>\n";
    }
  }
  echo "</ul>\n";
  echo "<a href='?file=$_GET[file]&amp;options=showOk'>Ajoute l'option showOk</a>\n";
  die();
}

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body><pre>\n";

checkFile($_GET['file'], $_GET['options'] ?? '');
