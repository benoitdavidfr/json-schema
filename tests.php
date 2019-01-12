<?php
/*PhpDoc:
name: tests.php
title: tests.php - tests de la classe JsonSchema
doc: |
journal: |
  11/1/2019
    première version
*/
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
require_once __DIR__.'/jsonschema.inc.php';

$filetests = ['tests','tests2'];
//$filetests = ['tests2'];
//$filetests = ['tests'];

$verbose = false;
$metaSchema = new JsonSchema(__DIR__.'/json-schema.schema.json');

// affichage de tous les tests sour la forme d'un tableau
if (!isset($_GET['no'])) {
  echo "<h2>Tests de recette</h2>\n";
  foreach($filetests as $file) {
    $txt = file_get_contents(__DIR__."/$file.yaml");
    $tests = Yaml::parse($txt, Yaml::PARSE_DATETIME);
    echo "<h2>$tests[title]</h2>\n";

    // vérification que le schéma du doc des tests est conforme au méta-schéma
    $status = $metaSchema->check($tests['jSchema']);
    if ($status->ok())
      echo "ok schéma des tests conforme au méta-schéma<br>\n";
    else
      $status->showErrors();
    
    // vérification que le contenu du doc des tests est conforme à son schéma
    $schema = new JsonSchema($tests['jSchema'], false);
    $status = $schema->check($tests);
    if ($status->ok())
      echo "ok contenu des tests conforme au schéma<br>\n";
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
          if (isset($test['data']['$ref']))
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
        echo "<tr><td><a href='?no=$nosch'>$sch[title]</a></td>";
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

// exécution d'un test particulier
$txt = file_get_contents(__DIR__."/$_GET[file].yaml");
$tests = Yaml::parse($txt, Yaml::PARSE_DATETIME);
list($nosch, $notest) = explode('.', $_GET['no']);
$test = $tests['schemas'][$nosch]['tests'][$notest];
$schema = new JsonSchema($tests['schemas'][$nosch]['schema']);
if (isset($test['data']['$ref'])) {
  echo "lecture du fichier ",$test['data']['$ref'],"<br>";
  $data = JsonSchema::jsonfile_get_contents($test['data']['$ref']);
  var_dump($data);
}
else
  $data = $test['data'];
$status = $schema->check($data);
echo "<h3>",$tests['schemas'][$nosch]['title'],"</h3>\n";
echo '<pre>',Yaml::dump($tests['schemas'][$nosch]['schema'], 999),"</pre>\n";
echo '<pre>',Yaml::dump($data, 999),"</pre>\n";

if ($status->ok() == $test['result'])
  echo "ok: status=",$status->ok()?'ok':'KO',", result=",$test['result']?'ok':'KO',"<br>\n";
elseif (!$status->ok())
  echo "<td><pre>",json_encode($status->errors()),"</pre></td></tr>\n";
else
  echo "<td><b>Erreur non détectée",isset($test['comment']) ? ", $test[comment]": '',"</b></td></tr>\n";

