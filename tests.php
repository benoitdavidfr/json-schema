<?php
/*PhpDoc:
name: tests.php
title: tests.php - éxécute un ensemble de tests de la classe JsonSchema
doc: |
  Par défaut lit les différents fichiers de test et exécute chaque test.
  Permet aussi dexécuter un test particulier.
journal: |
  11/1/2019
    première version
*/
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
require_once __DIR__.'/jsonschema.inc.php';

$filetests = ['tests','testsformat','tests2'];
//$filetests = ['tests2'];
//$filetests = ['tests'];

$verbose = false;
$metaSchema = new JsonSchema(__DIR__.'/json-schema.schema.json');

//echo "<pre>"; print_r($_SERVER); die();

// affichage de tous les tests sour la forme d'un tableau
if (!isset($_GET['no'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tests</title></head><body>\n";
  echo "<h2>Tests de recette</h2>\n";
  foreach($filetests as $file) {
    $txt = file_get_contents(__DIR__."/$file.yaml");
    if ($_SERVER['SERVER_NAME']<>'localhost')
      $txt = str_replace('http://localhost', "http://$_SERVER[SERVER_NAME]", $txt);
    $tests = Yaml::parse($txt, Yaml::PARSE_DATETIME);
    echo "<h2>$tests[title]</h2>\n";

    // vérification que le schéma du doc des tests est conforme au méta-schéma
    $status = $metaSchema->check($tests['jSchema']);
    if ($status->ok())
      echo "ok schéma des tests conforme au méta-schéma json-schema draft-07<br>\n";
    else {
      echo "Le schéma des tests n'est pas conforme au méta-schéma json-schema draft-07<br>\n";
      $status->showErrors();
    }
    
    // vérification que le contenu du doc des tests est conforme à son schéma
    $schema = new JsonSchema($tests['jSchema'], false);
    $status = $schema->check($tests);
    if ($status->ok())
      echo "ok contenu des tests conforme à leur schéma<br>\n";
    else
      $status->showErrors();

    echo "<table border=1>";
    foreach ($tests['schemas'] as $nosch => $sch) {
      if (!isset($sch['schema'])) {
        echo "<tr><td>$sch[title]</td><td colspan=2>NO SCHEMA</td></tr>\n";
        continue;
      }
      try {
        $schema = new JsonSchema($sch['schema'], $verbose);
        foreach ($sch['tests'] as $notest => $test) {
          if (is_array($test['data']) && isset($test['data']['$ref']))
            $data = JsonSchema::jsonfile_get_contents($test['data']['$ref']);
          else
            $data = $test['data'];
          $status = $schema->check($data, true);
          $no = "$nosch.$notest";
          echo "<tr><td><a href='?file=$file&amp;no=$no'>$sch[title]</a></td><td>",
               json_encode($test['data']),"</td>";
  
          if ($status->ok() == $test['result'])
            echo "<td>ok</td></tr>\n";
          elseif (!$status->ok())
            echo "<td><pre>",json_encode($status->errors()),"</pre></td></tr>\n";
          else
            echo "<td><b>Erreur non détectée",isset($test['comment']) ? ", $test[comment]": '',"</b></td></tr>\n";
        }
      }
      catch (Exception $e) {
        echo "<tr><td><a href='?file=$file&amp;no=$nosch'>$sch[title]</a></td>";
        if (isset($sch['schemaErrorComment']))
          echo "<td colspan=2>exception $sch[schemaErrorComment] ok</td></tr>\n";
        else
          echo "<td colspan=2><b>exception ",$e->getMessage()," KO</b></td></tr>\n";
      }
    }
    echo "</table>\n";
  }
  die();
}

// réalise un test et affiche le résultat
function testAndShowResult(string $title, $def, JsonSchema $schema, $data, bool $result, string $comment) {
  if (is_array($data) && isset($data['$ref'])) {
    echo "lecture du fichier ",$data['$ref'],"<br>";
    $data = JsonSchema::jsonfile_get_contents($data['$ref']);
    var_dump($data);
  }
  $status = $schema->check($data);
  echo "<h3>$title</h3>\n";
  echo '<pre>',Yaml::dump($def, 999),"</pre>\n";
  echo '<pre>',Yaml::dump($data, 999),"</pre>\n";

  if ($status->ok() && $result)
    echo "ok: status=ok, result=ok<br>\n";
  elseif (!$status->ok() && !$result)
    echo "ok: status=KO, result=KO<br>\n",
         "<pre>",JsonSchema::json_encode($status->errors()),"</pre>\n";
  elseif (!$status->ok())
    echo "<pre>",JsonSchema::json_encode($status->errors()),"</pre>\n";
  else
    echo "<b>Erreur non détectée $comment</b>\n";
}

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tests $_GET[file] $_GET[no]</title></head><body>\n";
// exécution d'un test particulier
$txt = file_get_contents(__DIR__."/$_GET[file].yaml");
if ($_SERVER['SERVER_NAME']<>'localhost')
  $txt = str_replace('http://localhost', "http://$_SERVER[SERVER_NAME]", $txt);
$tests = Yaml::parse($txt, Yaml::PARSE_DATETIME);
if (strpos($_GET['no'], '.') === false) {
  $nosch = $_GET['no'];
  $schema = new JsonSchema($tests['schemas'][$nosch]['schema'], true);
  
  foreach ($tests['schemas'][$nosch]['tests'] as $test)
    testAndShowResult(
      $tests['schemas'][$nosch]['title'],
      $tests['schemas'][$nosch]['schema'],
      $schema,
      $test['data'],
      $test['result'],
      isset($test['comment']) ? $test['comment'] : ''
    );
}
else {
  list($nosch, $notest) = explode('.', $_GET['no']);
  $schema = new JsonSchema($tests['schemas'][$nosch]['schema'], true);
  $test = $tests['schemas'][$nosch]['tests'][$notest];
  testAndShowResult(
    $tests['schemas'][$nosch]['title'],
    $tests['schemas'][$nosch]['schema'],
    $schema,
    $test['data'],
    $test['result'],
    isset($test['comment']) ? $test['comment'] : ''
  );
}
