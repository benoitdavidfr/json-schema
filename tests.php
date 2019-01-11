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

echo "<h2>Tests</h2>\n";
$txt = file_get_contents(__DIR__.'/tests.yaml');
$tests = Yaml::parse($txt, Yaml::PARSE_DATETIME);

if (!isset($_GET['no'])) {
  echo "<table border=1>";
  foreach ($tests['schemas'] as $nosch => $sch) {
    if (!isset($sch['schema'])) {
      echo "<tr><td>$sch[title]</td><td>NO SCHEMA</td><td></td></tr>\n";
      continue;
    }
    try {
      $schema = new JsonSchema($sch['schema']);
      foreach ($sch['tests'] as $notest => $test) {
        $status = $schema->check($test['instance']);
        $no = "$nosch.$notest";
        echo "<tr><td><a href='?no=$no'>$sch[title]</a></td><td>",json_encode($test['instance']),"</td>";
    
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
  die();
}

list($nosch, $notest) = explode('.', $_GET['no']);
$schema = new JsonSchema($tests['schemas'][$nosch]['schema']);
$test = $tests['schemas'][$nosch]['tests'][$notest];
$status = $schema->check($test['instance']);
echo "<h3>",$tests['schemas'][$nosch]['title'],"</h3>\n";
echo '<pre>',Yaml::dump($tests['schemas'][$nosch]['schema'], 999),"</pre>\n";
echo '<pre>',Yaml::dump($test['instance'], 999),"</pre>\n";

if ($status->ok() == $test['result'])
  echo "ok: status=",$status->ok()?'ok':'KO',", result=",$test['result']?'ok':'KO',"<br>\n";
elseif (!$status->ok())
  echo "<td><pre>",json_encode($status->errors()),"</pre></td></tr>\n";
else
  echo "<td><b>Erreur non détectée",isset($test['comment']) ? ", $test[comment]": '',"</b></td></tr>\n";
