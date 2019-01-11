<?php
/*PhpDoc:
name: index.php
title: index.php - test de la classe JsonSchema
doc: |
journal: |
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
$bookmarks = [ 'ex', 'geojson' ];
  
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
  $doc = JsonSchema::jsonfile_get_contents(__DIR__.'/json-schema.schema.json');
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
  ];
  $schema = isset($_GET['schema']) ? $_GET['schema'] : '';
  $instance = isset($_GET['instance']) ? $_GET['instance'] : "Coller ici l'instance";
  $select = "<select name='schema'>\n";
  foreach ($schema_choices as $name => $title)
    $select .= "<option value='$name'>$title</option>\n";
  $select .= "</select>";
  echo <<<EOT
<form>
Schema: $select
<br>
Instance:<br>
<textarea name="instance" rows="20" cols="100">$instance</textarea><br>
<input type='hidden' name='action' value='fchoice'>
<input type="submit">
</form>
<a href='?'>Retour à l'accueil</a>
EOT;
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
      echo "Le texte n'est ni du Yaml (",$e->getMessage(),"), ni du JSON.<br>\n";
      die();
    }
  }
  if ($_GET['lang']=='yaml')
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

  if ($key = isset($content['json-schema']) ? 'json-schema' : (isset($content['schema']) ? 'schema' : null)) {
    $schema = new JsonSchema($content[$key]);
    if (!isset($content['data']))
      echo "Pas d'instance trouvée dans le fichier<br>\n";
    else {
      $status = $schema->check($content['data']);
      if ($status->ok()) {
        $status->showWarnings();
        echo "ok instance conforme au schéma<br>\n";
      }
      else
        $status->showErrors();
    }
  }
  else {
    $schema = new JsonSchema(__DIR__.'/json-schema.schema.json');
    $status = $schema->check($content);
    if ($status->ok()) {
      $status->showWarnings();
      echo "ok schéma conforme au schéma des schéma<br>\n";
    }
    else
      $status->showErrors();
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