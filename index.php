<?php
/**
 * index.php - utilisation de la classe JsonSchema
 *
 * Fournit les fonctionnalités suivantes:
 *   - parcours interactif des fichiers json/yaml et sélection d'un fichier pour le valider par rapport à son schema
 *     s'il est défini
 *   - validation d'un doc par rapport à un schéma tous les 2 saisis interactivement
 *   - validation d'un doc saisi interactivement par rapport à un schéma prédéfini
 *   - conversion interactive entre JSON, Yaml et valeur Php évaluable par eval("return $value")
 * journal:
 *   1/8/2022:
 *     - corrections détectées par PhpStan level 16
 *   21/7/2022:
 *     - dans l'action convi, dans le dump Yaml, ajout de l'option Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
 *     - dans l'action convi, suppression du return de tête dans format Php
 *   14/4/2022:
 *     - modif paramètres d'affichage dans ?action=check
 *   19/2/2021:
 *     - ajout d'un lien vers checkjsonptr.php
 *     - correction des conversions (action=convert)
 *   16/2/2019:
 *     ajout possibilité de conversion interactive depuis et vers du code Php évaluable par eval()
 *   24/1/2019:
 *     utilisation du mot-clé $schema à la place de jSchema
 *   22/1/2019:
 *     ajout du test de validation des examples et counterexamples des définitions
 *   20/1/2019:
 *     ajout d'une récupération d'exception dans le check
 *     ajout du test de validation des examples et counterexamples
 *   9/1/2019
 *     ajout conversion interactive JSON <-> Yaml
 *   9/1/2019
 *     ajout vérification de la conformité d'un schéma au méta-schéma
 *   2/1/2019
 *     ajout conversion JSON <> Yaml
 *     ajout navigation dans les répertoires
 *   1/1/2019
 *     première version
 */
ini_set('memory_limit', '512M');
set_time_limit(2*60);

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/jsonschema.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// liste des signets du menu initial
$bookmarks = [ 'ex', 'geojson', '..' ];
  
// si le paramètre file n'est pas défini alors affichage des signets
if (!isset($_GET['file']) && !isset($_GET['action'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema</title></head><body>\n";
  echo "Permet de vérifier la conformité d'une instance à un schéma, de convertir entre JSON et Yaml ",
        "ou de naviguer dans les répertoires pour sélectionner des fichiers Yaml ou JSON :<br>\n";
  foreach ($bookmarks as $file)
    echo " - <a href='?action=check&amp;file=$file'>vérifie conformité $file</a>",
         " / <a href='?action=convert&amp;file=$file'>convertit</a><br>\n";
  echo " - <a href='?action=form'>saisie dans un formulaire de l'instance et du schéma</a><br>\n";
  echo " - <a href='?action=fchoice'>
    saisie dans un formulaire de l'instance et choix d'un schéma prédéfini</a><br>\n";
  echo " - <a href='?action=convi'>conversion interactive</a></p>\n";
  echo "Permet aussi de <a href='checkjsonptr.php'>vérifier les pointeurs JSON d'un fichier Yaml</a>.<br>\n";
  die();
}

// fonction générant le formulaire pour l'action form
function form(string $schema, string $instance, bool $schemaOk, bool $instanceOk): string {
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

if (0) { // @phpstan-ignore-line
  $txt = file_get_contents(__DIR__.'/json-schema.schema.json');
  $doc = json_decode($txt, true);
  var_dump($doc);
  die("FIN ligne ".__LINE__);
}
if (0) { // @phpstan-ignore-line
  $doc = JsonSch::file_get_contents(__DIR__.'/json-schema.schema.json');
  var_dump($doc);
  die("FIN ligne ".__LINE__);
}

// saisie dans un formulaire de l'instance et du schéma
if (($_GET['action'] ?? null)=='form') {
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
if (($_GET['action'] ?? null)=='fchoice') {
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

/**
 * is_list - le par. est-il une liste ?
 *
 * cad un array dont les clés sont la liste des n-1 premiers entiers positifs, [] est une liste
 *
 * @param mixed $list
 */
function is_list(mixed $list): bool { return is_array($list) && !is_assoc_array($list); }

/**
 * asPhpValue - fabrique une chaine de code Php correspondant à la valeur $value issue d'un parse Yaml
 *
 * cette chaine doit pouvoir être évaluée en Php par eval() en ajoutant return devant
 *
 * @param mixed $value
 * @param int $level
 * @return string
 */
function asPhpValue(mixed $value, int $level=0): string {
  if (is_string($value))
    $src = '"'.str_replace('"','\"',$value).'"';
  elseif (is_numeric($value))
    $src = (string)$value;
  elseif (is_bool($value))
    $src = $value ? 'true' : 'false';
  elseif (is_null($value))
    $src = 'null';
  elseif (is_object($value) && (get_class($value)=='DateTime'))
    $src = "new DateTime('".$value->format('Y-m-d H:i:s')."')";
  
  elseif (is_array($value)) {
    $src = "[\n";
    if (is_assoc_array($value)) {
      foreach ($value as $k => $v) {
        $src .= str_repeat('  ', $level+1)
          .asPhpValue($k, $level+1)
          .' => '
          .asPhpValue($v, $level+1)
          .(is_array($v) ? '' : ",\n");
      }
    }
    else {
      foreach ($value as $v) {
        $src .= str_repeat('  ', $level+1)
          .asPhpValue($v, $level+1)
          .(is_array($v) ? '' : ",\n");
      }
    }
    $src .= str_repeat('  ', $level).']'.($level ? ",\n" : '');
  }
  else
    throw new Exception("Cas non prévu");
  return $src.($level? '' : ";\n");
}


if (($_GET['action'] ?? null)=='convi') {
  $text = $_GET['txt'] ?? $_POST['txt'] ?? '';
  $lang = $_GET['lang'] ?? $_POST['lang'] ?? 'yaml';
  echo "<form method='POST'><table border=1>
  <tr><td><textarea name='txt' rows='20' cols='100'>$text</textarea></td></tr>
  <tr><td>lang: 
    <input type='radio' name='lang' value='yaml'",($lang=='yaml')?' checked':'',">Yaml
    <input type='radio'' name='lang' value='json'",($lang=='json')?' checked':'',"> JSON
    <input type='radio'' name='lang' value='php'",($lang=='php')?' checked':'',"> Php
    <input type='radio'' name='lang' value='dump'",($lang=='dump')?' checked':'',"> dump
  </td></tr>
  <tr><td><center><input type='submit'></center></td></tr>
  <input type='hidden' name='action' value='convi'>
  </table></form>
  <a href='?'>Retour à l'accueil</a><br>
";
  try {
    $doc = Yaml::parse($text, Yaml::PARSE_DATETIME);
  } catch(Exception $e) {
    if (($doc = json_decode($text, true)) === null) {
      try {
        $doc = eval("return $text");
      } catch(ParseError $e2) {
        echo "Le texte n'est ni du Yaml (",$e->getMessage(),"),<br>",
             "ni du JSON (",json_last_error_msg(),"),<br>",
             "ni du Php (",$e2->getMessage(),")",
             "<br>\n";
        die();
      }
    }
  }
  echo '<pre>';
  switch($lang) {
    case 'yaml': {
      echo Yaml::dump($doc, 999, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      break;
    }
    case 'php': {
      echo asPhpValue($doc);
      break;
    }
    case 'dump': {
      var_dump($doc);
      break;
    }
    default: {
      echo json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
  }
  echo "</pre>\n";
  die();
}

// navigation dans les répertoires
if (is_dir($dir = __DIR__."/$_GET[file]")) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema find</title></head><body>\n";
  $files = [];
  if ($dh = opendir($dir)) {
    while (($file = readdir($dh)) !== false) {
      $pos = strrpos($file, '.');
      $ext = ($pos !== false) ? substr($file, $pos+1) : null;
      //echo "ext=$ext<br>\n";
      if (in_array($ext, ['yaml','json','geojson','php']) || is_dir("$dir/$file"))
        $files[$file] = 1;
    }
    closedir($dh);
    ksort($files);
    foreach (array_keys($files) as $file) {
      echo "<a href='?action=$_GET[action]&amp;file=$_GET[file]/$file'>$file</a><br>\n";
    }
  }
  echo "<a href='?'><i>Retour</i></a><br>\n";
  die();
}

// les fichiers à tester contiennent soit des données spécifiées par un schéma soit un schéma
// dans le premier cas: vérification de la conformité des données au schéma et du schéma au méta-schéma
// dans le second: vérification de la conformité du schéma au méta-schéma et validation des exemples
// et contre-exemples au schéma
// Dans un fichier de données, le schéma est défini par le champ $schema
if ($_GET['action'] == 'check') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema check</title></head><body>\n";
  $verbose = isset($_GET['verbose']);
  if (!$verbose)
    echo "<a href='?action=$_GET[action]&amp;file=$_GET[file]&amp;verbose=true'>verbose</a><br>\n";
  
  //try {
  $content = JsonSch::file_get_contents(__DIR__."/$_GET[file]");
    //} catch (Exception $e) {
    //die("Erreur de lecture de $_GET[file] : ".$e->getMessage());
    //}

  echo "<pre>",Yaml::dump([$_GET['file']=> $content], 999, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";

  $metaschema = new JsonSchema(__DIR__.'/json-schema.schema.json', $verbose);
  if (!isset($content['$schema']))
    die('Erreur $schema non défini'."\n");
  if (in_array($content['$schema'], JsonSchema::SCHEMAIDS)) { // c'est un schema, je le valide / au méta-schéma
    //echo "C'est un schéma<br>\n";
    $metaschema->check($content, [
      'showOk'=> "ok schéma conforme au méta-schéma<br>\n",
      'showErrors'=> "KO schéma NON conforme au méta-schéma<br>\n",
    ]);
    // puis je valide les exemples et les contre-exemples du schema et des définitions
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
        $defSch = new JsonSchema(__DIR__."/$_GET[file]#/definitions/$defName", $verbose);
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
  
  else { // c'est un document à valider par rapport à son schema
    //echo "c'est un document à valider par rapport à son schema<br>\n";
    JsonSchema::autoCheck(__DIR__."/$_GET[file]", [
    //JsonSchema::autoCheck($content, [
      'showWarnings'=> "ok instance conforme au schéma<br>\n",
      'showErrors'=> "KO instance NON conforme au schéma<br>\n",
      'verbose'=> $verbose,
    ]);
  }
  
  die();
}

function fileExtension(string $path): string {
  if (($pos = strrpos($path, '.')) === false)
    return '';
  return substr($path, $pos+1);
}

// conversion JSON <> Yaml
if ($_GET['action'] == 'convert') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>schema convert</title></head><body>\n";
  if (in_array(fileExtension($_GET['file']), ['yaml','yml']))
    echo "<pre>",
          json_encode(Yaml::parse(
            file_get_contents(__DIR__."/$_GET[file]"), Yaml::PARSE_DATETIME),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          "</pre>\n";
  elseif (in_array(fileExtension($_GET['file']), ['json','geojson']))
    echo "<pre>",
         Yaml::dump(json_decode(file_get_contents(__DIR__."/$_GET[file]"), true), 999, 2),
         "</pre>\n";
  else
    die("$_GET[file] ni json ni yaml");
  die();
}
