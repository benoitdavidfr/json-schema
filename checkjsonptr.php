<?php
/**
 * checkjsonptr.php - Vérifie la validité de pointeurs JSON
 *
 * Appelé comme script, permet de sélectionner interactivement un fichier Yaml ou JSON pour y vérifier
 * que les pointeurs JSON sont déréfencables.
 * De plus, en fonction des options, tous les fichiers référencés locaux peuvent aussi être vérifiés.
 * Pour faciliter la réutilisation du code, définit la classe JsonPointer regroupant les fonctions peuvant être appelées
 * directement.
 *
 * journal:
 *  1/8/2022:
 *   - corrections détectées par PhpStan level 16
 *  17-19/2/2021:
 *   - création
*/

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: JsonPointer
title: class JsonPointer - classe technique regroupant les fonctions comme méthodes statiques
methods:
*/
class JsonPointer {
  const VERSION = "version du 1/8/2022";
  
  /**
   * findJsonPointers - retourne les pointeurs JSON du document $yaml
   *
   * Trouve dans le document $yaml les pointeurs JSON et en retourne la liste sous la forme [path => pointer]
   * où path est le chemin dans $yaml et pointer est l'array définissant le pointeur.
   * Le paramètre $keys est utilisé pour les appels récursifs.
   *
   * @param string $path
   * @param mixed $yaml
   * @param array<int, string> $keys
   * @return array<string, array<string, string>>
   */
  static function findJsonPointers(string $path, mixed $yaml, array $keys=[]): array {
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
          $jptrs = array_merge($jptrs, self::findJsonPointers($path, $value, $lkeys));
        }
      }
    }
    return $jptrs;
  }

  /**
   * deref - Retourne le fragment de $yaml défini par $fragId
   *
   * Retourne le fragment de $yaml défini par $fragId, fragId ne commence pas par # mais par /
   * S'il existe bien retourne ['value'=> value], si non retourne ['error'=> path] où path est le chemin dans $yaml
   * qui génère l'erreur.
   * filepath est une URL ou un chemin local et est utilisé pour fabriquer le message d'erreur.
   * $keys et $okkeys sont utilisées pour les appels récursifs,
   * $keys est la liste des clés restantes à tester, $okkeys est la liste des clés testées ok
   *
   * @param string $filePath
   * @param array<mixed> $yaml
   * @param string $fragId
   * @param array<int, string>|null $keys
   * @param array<int, string> $okkeys
   * @return array<mixed>
   */
  static function deref(string $filePath, array $yaml, string $fragId, ?array $keys=null, array $okkeys=[]): array {
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
        return self::deref($filePath, $yaml[$key0], $fragId, $keys, $okkeys);
      }
    }
  }
  
  /**
   * pathIsRemote - teste si le chemin est distant
   *
   * @param string $path
   * @return bool
   */
  static function pathIsRemote(string $path): bool {
    return (substr($path, 0, 7) == 'http://') || (substr($path, 0, 8) == 'https://');
  }
  
  /**
   * filePath - extrait du $ref le chemin du fichier et évent. le convertit
   *
   * extrait du $ref le chemin du fichier et s'il est relatif, le convertit en absolu, et
   * s'il est local et que $srcPath est distant alors le convertit en distant.
   * $srcPath est le chemin du fichier qui contient le pointeur.
   *
   * @param string $ref
   * @param string $srcPath
   * @return string
   */
  static function filePath(string $ref, string $srcPath): string {
    //echo "filePath($ref, $srcPath)";
    $filePath = self::filePath2($ref, $srcPath);
    //echo " -> $filePath\n";
    return $filePath;
  }
  static function filePath2(string $ref, string $srcPath): string {
    if (($pos = strpos($ref, '#')) === false)
      $filePath = $ref;
    else
      $filePath = substr($ref, 0, $pos);
    // filePath est la partie scheme/host/path/search de $ref
    if (!$filePath) // $ref est un pointeur interne au même fichier
      return $srcPath;
    elseif (self::pathIsRemote($filePath)) // $ref est distant
      return $filePath;
    elseif (substr($ref, 0, 1) <> '/') // $ref est local et relatif
      return dirname($srcPath)."/$filePath";
    elseif (!self::pathIsRemote($srcPath)) // $ref est local et absolu et $srcPath n'est pas distant
      return $filePath;
    else { // $ref est local et absolu et $srcPath est distant
      $src = self::decompPath($srcPath);
      return "$src[scheme]://$src[host]$filePath";
    }
  }
  static function TEST_filePath(): never {
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body><pre>\n";
    self::filePath('#/defs/def0', '/var/www/html/geovect/dcat/testjptr/main.yaml');
    self::filePath('http://host/path#frag', 'xx');
    self::filePath('/path#frag', 'xx');
    self::filePath('../path#frag', '/dir1/dir2/srcpath');
    self::filePath('/path#frag', 'http://host/hostpath');
    die("Fin TEST_filePath\n");
  }

  /**
   * decompPath  - décompose le chemin en scheme/host/path/search/fragId
   *
   * @param string $fpath
   * @return array<string, string>
   */
  static function decompPath(string $fpath): array {
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
  static function TEST_decompPath(): never { // TEST de decompPath
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body><pre>\n";
    print_r(self::decompPath('http://host.fr/a/b/c?sss#fff'));
    print_r(self::decompPath('http://host.fr/a/b/c#fff'));
    print_r(self::decompPath('http://host.fr/a/b/c?sss'));
    print_r(self::decompPath('http://host.fr/a/b/c#ff?sss'));
    /*print_r(self::decompPath('/a/b/c?sss#fff'));
    print_r(self::decompPath('/a/b/c#fff'));
    print_r(self::decompPath('/a/b/c?sss'));
    print_r(self::decompPath('/a/b/c'));*/
    print_r(self::decompPath('a/b/c?sss#fff'));
    print_r(self::decompPath('a/b/c#fff'));
    print_r(self::decompPath('a/b/c?sss'));
    print_r(self::decompPath('a/b/c'));
    /*print_r(self::decompPath('#fff'));
    print_r(self::decompPath('?sss#fff'));
    print_r(self::decompPath('?sss'));
    print_r(self::decompPath(''));*/
    die("Fin TEST_decompPath\n");
  }

  /**
   * checkJsonPointer- vérifie la validité d'un pointeur
   *
   * Dans le fichier local dont le chemin est $filePath et le contenu $yaml, vérifie la validité du pointeur $jsonPtr
   * défini au chemin $jPtrPath en utilisant les options $options, Renvoie vrai ssi le pointeur est valide.
   * $filePath est le chemin absolu local défini par rappport à $_SERVER['DOCUMENT_ROOT'].
   * Ne fonctionne pas sur les $filePath distants.
   * Les pointeurs erronés sont affichés.
   *  Si $options['showOk'] est défini et vrai alors affiche aussi les pointeurs corrects.
   *
   *  Terminologie:
   *    * distant (remote) / local - pointeur vers une machine différente de la source ou au sein de la même machine
   *    * externe / interne - pointeur vers un autre fichier que la source ou au sein du même fichier
   *
   * @param string $filePath
   * @param array<mixed> $yaml
   * @param string $jPtrPath
   * @param array<string, mixed> $jsonPtr
   * @param array<string, bool> $options
   * @return bool
   */
  static function checkJsonPointer(string $filePath, array $yaml, string $jPtrPath, array $jsonPtr, array $options): bool {
    //echo "checkJsonPointer($filePath, $jPtrPath, ",json_encode($jsonPtr),")\n";
    $dcmp = self::decompPath($jsonPtr['$ref']);
    if (!$dcmp['host']) { // pointeur local à la machine
      if (!$dcmp['path'] && !$dcmp['search']) { // pointeur interne au même fichier
        if (!$dcmp['fragId']) { // pointeur vide
          echo "KO(",__LINE__,"): pointeur $jPtrPath vide\n";
          return false;
        }
        else { // pointeur non vide interne au même fichier
          $deref = self::deref($filePath, $yaml, $dcmp['fragId']);
        }
      }
      else { // pointeur externe, ie vers un autre fichier sur la même machine 
        if (substr($dcmp['path'], 0, 1) <> '/') // chemin relatif
          $path = dirname($filePath).'/'.$dcmp['path'];
        else // chemin absolu
          $path = $dcmp['path'];
        if (!file_exists("$_SERVER[DOCUMENT_ROOT]$path")) {
          echo "KO(",__LINE__,"): pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erroné en $dcmp[path]\n";
          return false;
        }
        //echo "path=$path\n\n";
        $yaml = Yaml::parseFile("$_SERVER[DOCUMENT_ROOT]$path");
        $deref = self::deref($path, $yaml, $dcmp['fragId']);
      }
    }
    else { // pointeur distant, ie vers une autre machine
      $path = "$dcmp[scheme]://$dcmp[host]$dcmp[path]$dcmp[search]";
      if (($contents = @file_get_contents($path)) === false) {
        //echo "file_get_contents($path) faux\n";
        echo "KO(",__LINE__,"): pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erroné en $path\n";
        return false;
      }
      $yaml = Yaml::parse($contents);
      $deref = self::deref($path, $yaml, $dcmp['fragId']);
    }
    if (isset($deref['error'])) {
      echo "KO(",__LINE__,"): pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est erroné en $deref[error]\n";
      return false;
    }
    elseif ($options['showOk'] ?? null)
      echo "Ok: pointeur défini en ",($jPtrPath)," -> ",$jsonPtr['$ref']," est Ok\n";
    return true;
  }

  /**
   * checkFile - Vérifie tous les pointeurs contenus dans le fichier
   *
   * Vérifie tous les pointeurs contenus dans le fichier ayant $filePath pour chemin absolu local défini par rappport
   * à $_SERVER['DOCUMENT_ROOT']. $options peut contenir les options 'recursiveOnLocalFile' et 'showOk'.
   * Si options['recursiveOnLocalFile'] est défini et vrai alors teste aussi tous les fichiers référencés.
   * retourne comme clés la liste des chemins des fichiers testés.
   * Le paramètre $checkedFilePaths est uniquement utilisé pour les appels récursifs.
   *
   * @param string $filePath
   * @param array<string, bool> $options
   * @param array<string, 1> $checkedFilePaths
   * @return array<string, 1>
   */
  static function checkFile(string $filePath, array $options, array $checkedFilePaths=[]): array {
    $filePathsToCheck = []; // liste en clés des fichiers à tester
    if (($contents = @file_get_contents("$_SERVER[DOCUMENT_ROOT]$filePath")) === false) {
      echo "KO(",__LINE__,"): chemin de fichier $filePath erroné\n";
      $checkedFilePaths[$filePath] = 1;
      return $checkedFilePaths;
    }
    $yaml = Yaml::parse($contents);
    echo Yaml::dump([$filePath => ['jsonPtrs'=> self::findJsonPointers($filePath, $yaml)]], 3, 2);

    foreach (self::findJsonPointers($filePath, $yaml) as $jPtrPath => $jsonPtr) {
      if (self::checkJsonPointer($filePath, $yaml, $jPtrPath, $jsonPtr, $options)) {
        if ($options['recursiveOnLocalFile'] ?? null) {
          $destFilePath = self::filePath($jsonPtr['$ref'], $filePath);
          if (!self::pathIsRemote($destFilePath)
            && !isset($checkedFilePaths[$destFilePath]) 
              && !isset($filePathsToCheck[$destFilePath])
                && ($destFilePath <> $filePath)) {
            $filePathsToCheck[$destFilePath] = 1;
            echo "ajout $destFilePath\n";
          }
        }
      }
    }
    $checkedFilePaths[$filePath] = 1;
  
    foreach (array_keys($filePathsToCheck) as $filePathToCheck) {
      if (!isset($checkedFilePaths[$filePathToCheck]))
        $checkedFilePaths = array_merge($checkedFilePaths, self::checkFile($filePathToCheck, $options, $checkedFilePaths));
    }
    return $checkedFilePaths;
  }
  
  /**
   * browseFile - Navigue dans l'arborescence des fichiers pour en sélectionner un
   *
   * Permet de naviguer dans l'arborescence à partir de $dir pour sélectionner un des fichiers ayant pour extension
   * l'une de celles fournies dans le paramètre $fileExts et de sélectionner de plus une ou plusieurs des options
   * dont les clés sont définies dans $optionKeys.
   * Si le chemin passé en paramètre est un répertoire alors La méthode affiche du code Html pour choisir un fichier
   * ou un répertoire en rappelant le script avec en paramètres $_GET file le chemin du fichier ou du répertoire,
   * et options la liste des options sélectionnées. Cette affichage se termine par un die().
   * Si le chemin passé en paramètre $dir n'est pas un répertoire alors n'affiche rien et retourne.
   * La méthode est à rappeler en début de script.
   *
   * @param string $dir
   * @param array<int, string> $fileExts
   * @param array<int, string> $optionKeys
   */
  static function browseFile(string $dir, array $fileExts, array $optionKeys): void {
    if (!is_dir($dir))
      return;
    $files = [];
    echo "<b>Répertoire $dir :</b><ul>\n";
    if ($dh = opendir($dir)) {
      while (($file = readdir($dh)) !== false) {
        if ($file == '.') continue;
        $pos = strrpos($file, '.');
        $ext = ($pos !== false) ? substr($file, $pos+1) : null;
        //echo "ext=$ext<br>\n";
        if (in_array($ext, $fileExts) || is_dir("$dir/$file"))
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
  
    // Permet de modifier les options
    $options = ($_GET['options'] ?? null) ? array_fill_keys(explode(',', $_GET['options']), true) : [];
    //print_r($options); echo "<br>\n";
    foreach ($optionKeys as $option) {
      $opts2 = $options;
      if ($opts2[$option] ?? null) {
        unset($opts2[$option]);
        echo "<a href='?file=$dir&amp;options=",implode(',', array_keys($opts2)),"'>",
          "Enlève l'option $option</a><br>\n";
      }
      else {
        $opts2[$option] = true;
        echo "<a href='?file=$dir&amp;options=",implode(',', array_keys($opts2)),"'>",
          "Ajoute l'option $option</a><br>\n";
      }
    }
    die("--<br>".self::VERSION);
  }
};
//JsonPointer::TEST_filePath();
//JsonPointer::TEST_decompPath();


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__)))
  return;


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>checkjptr</title></head><body>\n";

$file = $_GET['file'] ?? realpath('..');

// navigation dans les répertoires pour sélectionner un fichier Yaml/JSON en permettant de sélectionner les options
JsonPointer::browseFile($file, ['yaml','yml','json','geojson'], ['showOk', 'recursiveOnLocalFile']);

echo "<pre>\n";

$checkedFilePaths = JsonPointer::checkFile(
  substr($_GET['file'], strlen($_SERVER['DOCUMENT_ROOT'])),
  ($_GET['options'] ?? null) ? array_fill_keys(explode(',', $_GET['options']), true) : []
);
echo Yaml::dump(['checkedFilePaths'=> array_keys($checkedFilePaths)], 9, 2);
