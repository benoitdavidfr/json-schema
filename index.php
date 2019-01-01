<?php
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
require_once __DIR__.'/jsonschema.inc.php';

if (!isset($_GET['file'])) {
  foreach (['person','geo','array','event','personhierarchy','loop','anyOf','typeaslist'] as $file)
    echo "<a href='?file=$file'>$file</a><br>\n";
  die();
}

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
