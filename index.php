<?php
/*PhpDoc:
name: index.php
title: index.php - test de la classe JsonSchema
doc: |
journal: |
  2/1/2019
    ajout conversion JSON <> Yaml
    ajout navigation dans les répertoires
  1/1/2019
    première version
*/
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
require_once __DIR__.'/jsonschema.inc.php';

// liste des signets du menu initial
$bookmarks = [ 'ex', 'geojson' ];
  
// si le paramètre file n'est pas défini alors affichage des signets
if (!isset($_GET['file'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema</title></head><body>\n";
  echo "Permet de vérfier la conformité d'une instance à un schéma, de convertir entre JSON et Yaml ",
        "ou de naviguer dans les répertoires pour sélectionner des fichiers Yaml ou JSON<br>\n";
  foreach ($bookmarks as $file)
    echo "<a href='?action=check&amp;file=$file'>check $file</a>",
         " / <a href='?action=convert&amp;file=$file'>convert</a><br>\n";
  die();
}

// navigation dans les répertoires
if (is_dir($dir = __DIR__."/$_GET[file]")) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema find</title></head><body>\n";
  if ($dh = opendir($dir)) {
    while (($file = readdir($dh)) !== false) {
      if (in_array(substr($file, -5), ['.yaml','.json']))
        $bfile = substr($file, 0, strlen($file)-5);
      elseif (is_dir("$dir/$file"))
        $bfile = $file;
      else
        continue;
      
      echo "<a href='?action=$_GET[action]&amp;file=$_GET[file]/$bfile'>$file</a><br>\n";
    }
    closedir($dh);
  }
  echo "<a href='?'><i>Retour</i></a><br>\n";
  die();
}

// affichage du fichier puis, s'il contient un schema, création du schéma, et, s'il contient des données,
// vérification de la conformité des données au schéma
if ($_GET['action'] == 'check') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema check</title></head><body>\n";
  if (is_file(__DIR__."/$_GET[file].yaml"))
    $content = Yaml::parse(file_get_contents(__DIR__."/$_GET[file].yaml"), Yaml::PARSE_DATETIME);
  elseif (is_file(__DIR__."/$_GET[file].json"))
    $content = json_decode(file_get_contents(__DIR__."/$_GET[file].json"), true);
  else
    die("$_GET[file] ni json ni yaml");

  echo "<pre>",Yaml::dump([$_GET['file']=> $content], 999),"</pre>\n";

  if (isset($content['json-schema'])) {
    $schema = new JsonSchema($content['json-schema']);
    if (isset($content['data'])) {
      if ($schema->check($content['data'])) {
        $schema->showWarnings();
        echo "ok<br>\n";
      }
      else
        $schema->showErrors();
    }
  }
  die();
}

// conversion JSON <> Yaml
if ($_GET['action'] == 'convert') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema convert</title></head><body>\n";
  if (is_file(__DIR__."/$_GET[file].yaml"))
    echo "<pre>",
          json_encode(Yaml::parse(
            file_get_contents(__DIR__."/$_GET[file].yaml"), Yaml::PARSE_DATETIME),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          "</pre>\n";
  elseif (is_file(__DIR__."/$_GET[file].json"))
    echo "<pre>",
         Yaml::dump(json_decode(file_get_contents(__DIR__."/$_GET[file].json"), true), 999, 2),
         "</pre>\n";
  else
    die("$_GET[file] ni json ni yaml");
  die();
}