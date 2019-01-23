<?php
/*PhpDoc:
name: index.php
title: index.php - test de la classe JsonSchema
doc: |
  Fournit les fonctionnalités suivantes:
    - parcours interactif des fichiers json/yaml pour valider soit s'il existe par rapport à son jSchema
      soit sinon par rapport au méta-schéma JSON-Schema
    - validation d'un doc par rapport à un schéma tous les 2 saisis interactivement
    - validation d'un doc saisi interactivement par rapport à un schéma prédéfini
    - conversion interactive entre JSON et Yaml
journal: |
  22/1/2019:
    ajout du test de validation des examples et counterexamples des définitions
  20/1/2019:
    ajout d'une récupération d'exception dans le check
    ajout du test de validation des examples et counterexamples
  9/1/2019
    ajout conversion interactive JSON <-> Yaml
  9/1/2019
    ajout vérification de la conformité d'un schéma au méta-schéma
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
$bookmarks = [ 'ex', 'geojson', '..' ];
  
// si le paramètre file n'est pas défini alors affichage des signets
if (!isset($_GET['file']) && !isset($_GET['action'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema</title></head><body>\n";
  echo "Permet de vérfier la conformité d'une instance à un schéma, de convertir entre JSON et Yaml ",
        "ou de naviguer dans les répertoires pour sélectionner des fichiers Yaml ou JSON<br>\n";
  foreach ($bookmarks as $file)
    echo "<a href='?action=check&amp;file=$file'>check $file</a>",
         " / <a href='?action=convert&amp;file=$file'>convert</a><br>\n";
  echo "<a href='?action=form'>saisie dans un formulaire de l'instance et du schéma</a><br>\n";
  echo "<a href='?action=fchoice'>saisie dans un formulaire de l'instance et choix d'un schéma prédéfini</a><br>\n";
  echo "<a href='?action=convi'>conversion interactive</a><br>\n";
  die();
}

// fonction générant le formulaire pour l'action form
function form(string $schema, string $instance, bool $schemaOk, bool $instanceOk) {
  $schemaStyle = $schemaOk ? " style='color:blue;'" : " style='color:orange;'";
  $instanceStyle = $instanceOk ? " style='color:blue;'" : " style='color:orange;'";
  $form = <<<EOT
<form><table border=1>
  <tr><td$schemaStyle>
    Schema:<br>
    <textarea$schemaStyle name="schema" rows="20" cols="80">$schema</textarea>
  </td><td$instanceStyle>
    Instance:<br>
    <textarea$instanceStyle name="instance" rows="20" cols="80">$instance</textarea>
  </td></tr>
<input type='hidden' name='action' value='form'>
<tr><td colspan=2><center><input type="submit"></center></td></tr>
</table></form>
<a href='?'>Retour à l'accueil</a><br>
EOT;
  return $form;
}

if (0) {
  $txt = file_get_contents(__DIR__.'/json-schema.schema.json');
  $doc = json_decode($txt, true);
  var_dump($doc);
  die("FIN ligne ".__LINE__);
}
if (0) {
  $doc = JsonSch::file_get_contents(__DIR__.'/json-schema.schema.json');
  var_dump($doc);
  die("FIN ligne ".__LINE__);
}

// saisie dans un formulaire de l'instance et du schéma
if (isset($_GET['action']) && ($_GET['action']=='form')) {
  $schemaParDefaut = <<<'EOT'
# schéma par défaut
$schema: http://json-schema.org/draft-07/schema#
$id: http://georef.eu/schema/none

EOT;
  $schemaLang = '';
  $schemaTxt = isset($_GET['schema']) ? $_GET['schema'] : $schemaParDefaut;
  $instanceTxt = isset($_GET['instance']) ? $_GET['instance'] : "Coller ici l'instance";
  //echo form($schemaTxt, $instanceTxt, true, true);
  try {
    $schema = Yaml::parse($schemaTxt, Yaml::PARSE_DATETIME);
    $schemaLang = 'Yaml';
  } catch(Exception $e) {
    $schema = json_decode($schemaTxt, true);
    if ($schema === null) {
      echo form($schemaTxt, $instanceTxt, false, false);
      echo "Le schéma n'est ni du Yaml (",$e->getMessage(),"), ni du JSON.<br>\n";
      die();
    }
    $schemaLang = 'JSON';
  }
  if (is_null($schema)) {
    echo form($schemaTxt, $instanceTxt, false, false);
    die("Pas de schéma");
  }
  $metaschema = new JsonSchema(__DIR__.'/json-schema.schema.json');
  $statusSchema = $metaschema->check($schema);
  if (!$statusSchema->ok()) {
    echo form($schemaTxt, $instanceTxt, false, false);
    $statusSchema->showErrors();
    die();
  }
  
  $schema = new JsonSchema($schema);
  $instanceLang = '';
  try {
    $instance = Yaml::parse($instanceTxt, Yaml::PARSE_DATETIME);
    $instanceLang = 'Yaml';
  } catch(Exception $e) {
    $instance = json_decode($instanceTxt, true);
    if ($instance === null) {
      echo form($schemaTxt, $instanceTxt, true, false);
      echo "ok schéma $schemaLang conforme au méta-schéma<br>\n";
      echo "L'instance n'est ni du Yaml (",$e->getMessage(),"), ni du JSON.<br>\n";
      die();
    }
    $instanceLang = 'JSON';
  }
  $status = $schema->check($instance);
  echo form($schemaTxt, $instanceTxt, true, $status->ok());
  echo "ok schéma $schemaLang conforme au méta-schéma<br>\n";
  $statusSchema->showWarnings();
  if ($status->ok()) {
    echo "<br>ok instance $instanceLang conforme au schéma<br>\n";
    $status->showWarnings();
  }
  else {
    echo "<br>KO instance $instanceLang NON conforme au schéma<br>\n";
    $status->showErrors();
  }
  echo "<pre>instance = "; var_dump($instance); echo "</pre>\n";
  die();
}

// saisie dans un formulaire de l'instance et choix d'un schéma prédéfini
if (isset($_GET['action']) && ($_GET['action']=='fchoice')) {
  $schema_choices = [
    'json-schema.schema.json'=> "méta schéma JSON",
    'geojson/featurecollection.schema.json'=> "FeatureCollection GeoJSON",
    'geojson/feature.schema.json'=> "feature GeoJSON",
    'geojson/geometry.schema.yaml'=> "geometry GeoJSON",
  ];
  $schema = isset($_GET['schema']) ? $_GET['schema'] : array_keys($schema_choices)[0];
  $schemaTxt = file_get_contents(__DIR__."/$schema");
  $instanceTxt = isset($_GET['instance']) ? $_GET['instance'] : "Coller ici l'instance";
  $select = "<select name='schema'>\n";
  foreach ($schema_choices as $name => $title)
    $select .= "<option value='$name'".($name==$_GET['schema'] ? ' selected': '').">$title</option>\n";
  $select .= "</select>";
  echo <<<EOT
<form><table border=1>
  <tr><td>Schema: $select</td></tr>
  <tr>
    <td>Instance:<br><textarea name="instance" rows="20" cols="80">$instanceTxt</textarea></td>
    <td>Affichage du contenu du schema:<br><textarea rows="20" cols="79">$schemaTxt</textarea></td>
  </tr>
<input type='hidden' name='action' value='fchoice'>
<tr><td><input type="submit"></td></tr>
</table></form>
<a href='?'>Retour à l'accueil</a><br>
EOT;
  $schema = new JsonSchema(__DIR__."/$schema");
  try {
    $instance = Yaml::parse($instanceTxt, Yaml::PARSE_DATETIME);
  } catch(Exception $e) {
    $instance = json_decode($instanceTxt, true);
    if ($instance === null) {
      echo "L'instance n'est ni du Yaml (",$e->getMessage(),"), ni du JSON (".json_last_error_msg().").<br>\n";
      die();
    }
    $instanceLang = 'JSON';
  }
  $status = $schema->check($instance, [
    'showWarnings'=> "ok instance conforme au schéma<br>\n",
    'showErrors'=> "KO instance NON conforme au schéma<br>\n",
  ]);
  
  die();
}

if (isset($_GET['action']) && ($_GET['action']=='convi')) {
  $text = isset($_GET['txt']) ? $_GET['txt'] : '';
  echo <<< EOT
<form><table border=1>
  <tr><td><textarea name="txt" rows="20" cols="100">$text</textarea></td></tr>
  <tr><td>lang: 
    <input type="radio" name='lang' value='yaml' checked>Yaml
    <input type="radio" name='lang' value='json'> JSON
  </td></tr>
<tr><td><center><input type="submit"></center></td></tr>
<input type='hidden' name='action' value='convi'>
</table></form>
<a href='?'>Retour à l'accueil</a><br>
EOT;
  try {
    $doc = Yaml::parse($text, Yaml::PARSE_DATETIME);
  } catch(Exception $e) {
    $doc = json_decode($text, true);
    if ($doc === null) {
      echo "Le texte n'est ni du Yaml (",$e->getMessage(),"), ni du JSON (".json_last_error_msg().").<br>\n";
      die();
    }
  }
  if (!isset($_GET['lang']) || ($_GET['lang']=='yaml'))
    echo '<pre>',Yaml::dump($doc, 999), "</pre>\n";
  elseif ($_GET['lang']=='json')
    echo '<pre>',json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "</pre>\n";
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

// les fichiers à tester contiennent soit des données spécifiées par un schéma soit un schéma
// dans le premier cas: vérification de la conformité des données au schéma et du schéma au méta-schéma
// dans le second uniquement vérification de la conformité au méta-schéma
// dans un fichier de données, le schéma est défini par le champ jSchema ou json-schema
if ($_GET['action'] == 'check') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema check</title></head><body>\n";
  $verbose = false;
  if (isset($_GET['verbose']))
    $verbose = true;
  else
    echo "<a href='?action=$_GET[action]&amp;file=$_GET[file]&amp;verbose=true'>verbose</a><br>\n";
  
  $fileext = is_file(__DIR__."/$_GET[file].yaml") ? 'yaml' : (is_file(__DIR__."/$_GET[file].json") ? 'json' : null);
  if (!$fileext)
    die("$_GET[file] ni json ni yaml");
  try {
    $content = JsonSch::file_get_contents(__DIR__."/$_GET[file].$fileext");
  } catch (Exception $e) {
    die("Erreur de lecture de $_GET[file].$fileext : ".$e->getMessage());
  }

  echo "<pre>",Yaml::dump([$_GET['file']=> $content], 999),"</pre>\n";

  $metaschema = new JsonSchema(__DIR__.'/json-schema.schema.json', $verbose);
  if (isset($content['jSchema'])) { # c'est un document à  valider par rapport à son schema
    $jSchema = (is_string($content['jSchema'])) ?
      JsonSch::file_get_contents(JsonSch::predef($content['jSchema']))
       : $content['jSchema'];
    $metaschema->check($jSchema, [
      'showOk'=> "ok schéma conforme au méta-schéma<br>\n",
      'showErrors'=> "KO schéma NON conforme au méta-schéma<br>\n",
    ]);
    JsonSchema::autoCheck(__DIR__."/$_GET[file].$fileext", [
    //JsonSchema::autoCheck($content, [
      'showWarnings'=> "ok instance conforme au schéma<br>\n",
      'showErrors'=> "KO instance NON conforme au schéma<br>\n",
      'verbose'=> $verbose,
    ]);
  }
  else { # c'est un schema, je le valide par rapport au méta-schéma
    $metaschema->check($content, [
      'showOk'=> "ok schéma conforme au méta-schéma<br>\n",
      'showErrors'=> "KO schéma NON conforme au méta-schéma<br>\n",
    ]);
    $schema = new JsonSchema($content, $verbose);
    foreach (['examples'=> 'exemple', 'counterexamples'=> 'contre-exemple'] as $key=> $label) {
      if (isset($content[$key])) { # et je vérifie les exemples et contre-ex
        foreach ($content[$key] as $i => $ex) {
          $title = !isset($ex['title']) ? $i : (!is_array($ex['title']) ? "\"$ex[title]\"" : json_encode($ex['title']));
          $schema->check($ex, [
            'showWarnings'=> "ok $label $title conforme au schéma<br>\n",
            'showErrors'=> "KO $label $title NON conforme au schéma<br>\n",
          ]);
        }
      }
    }
    if (isset($content['definitions'])) {
      foreach ($content['definitions'] as $defName => $definition) {
        //echo "Check $defName ",json_encode($definition),"<br>\n";
        $defSch = new JsonSchema(__DIR__."/$_GET[file].$fileext#/definitions/$defName", $verbose);
        foreach (['examples'=> 'exemple', 'counterexamples'=> 'contre-exemple'] as $key=> $label) {
          if (isset($definition[$key])) { # et je vérifie les exemples et contre-ex pour cette définition
            foreach ($definition[$key] as $i => $ex) {
              //echo "check de ",json_encode($ex),"<br>\n";
              $defSch->check($ex, [
                'showWarnings'=> "ok $label de $defName no $i conforme au schéma<br>\n",
                'showErrors'=> "KO $label de $defName no $i NON conforme au schéma<br>\n",
              ]);
            }
          }
        }
      }
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