<?php
/*PhpDoc:
name: jsonschema.inc.php
title: jsonschema.inc.php - validation de la conformité d'un objet Php à un schéma JSON
classes:
doc: |
  Pour valider la conformité d'un objet Php à un schéma, il faut:
    - créer un objet JsonSchema en fournissant le contenu du schema sous la forme d'un array Php
    - appeler sur cet objet la méthode check avec l'instance Php à vérifier
    - analyser le statut retourné (classe JsonSch Status) par cette vérification
  voir https://json-schema.org/understanding-json-schema/reference/index.html (draft-06)
  La classe est utilisée avec des valeurs Php
  3 types d'erreurs sont gérées:
    - une structuration non prévue de schéma génère une exception
    - une non conformité d'une instance à un schéma fait échouer la vérification
    - une alerte peut être produite dans certains cas sans faire échouer la vérification
  Lorsque le schéma est conforme au méta-schéma, la génération d'une exception correspond à un bug du code.
  Ce validateur implémente la spec http://json-schema.org/draft-06/schema# en totalité.
journal: |
  19/1/2019:
    scission du fichier jsonschema.inc.php en jsonschema.inc.php et jsonschelt.inc.php
    ajout de JsonSch::deref() pour déréférencer un pointeur JSON
    permet d'utiliser les URL http://{name}.georef.eu/ pour des docs autres que des schémas
    ajout de la possibilité de définir des options d'affichage dans JsonSchema::check()
    ajout de JsonSchema::autoCheck() pour vérifier qu'une instance est conforme au schéma défini par le champ jSchema
  18/1/2019:
    Ajout fonctionnalité d'utilisation de schémas prédéfinis
  16-17/1/2019:
    Correction d'un bug sur items
    Modif de la logique de vérification pour ne pas traiter les ensembles de types comme des anyOf()
    Traitement de AdditionalItems comme schema
  15/1/2019:
    Correction d'un bug sur PropertyNames
  11-14/1/2018:
    Renforcement des tests et correction de bugs
    ajout additionalProperties, propertyNames, minProperties, maxProperties, minItems, maxItems, contains, uniqueItems
      minLength, maxLength, pattern, Generic Enumerated misc values, Generic const misc values, multiple, dependencies
      allOf, oneOf, not, Tuple validation, Tuple validation w additional items, format
    Publication sur Gihub
  9-10/1/2018:
    Réécriture complète en 3 classes: schéma JSON, élément d'un schéma et statut d'une vérification
    Correction d'un bug dans la vérification d'un schéma par le méta-schéma
  8/1/2018:
    BUG trouvé: lorsqu'un schéma référence un autre schéma dans le même répertoire,
    le répertoire de référence doit être le répertoire courant du schéma
  7/1/2019:
    BUG trouvé dans l'utilisation d'une définition dans un oneOf,
    oneOf coupe le lien vers le schema racine pour éviter d'enregistrer les erreurs
    alors que les référence vers une définition a besoin de ce lien
    voir http://localhost/schema/?action=check&file=ex/route500
    début de correction
    quand on a une hiérarchie de schéma, dans lequel chercher une définition ?
    a priori je prenais la racine mais ce n'est pas toujours le cas
    solution: distinuer les vrai schémas des pseudo-schémas qui sont des parties d'un schéma
  3/1/2019
    les fonctions complémentaires ne sont définies que si elles ne le sont pas déjà
    correction bug
  2/1/2019
    ajout oneOf
    correction du test d'une propriété requise qui prend la valeur nulle
    correction de divers bugs détectés par les tests sur des exemples de GeoJSON
    assouplissement de la détection dans $ref au premier niveau d'un schema
    ajout d'un mécanisme de tests unitaires
    ajout patternProperties et test sur http://localhost/yamldoc/?doc=dublincoreyd&ypath=%2Ftables%2Fdcmes%2Fdata
  1/1/2019
    première version
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/jsonschelt.inc.php';
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// test si un array est un tableau associatif ou une liste
if (!function_exists('is_assoc_array')) {
  function is_assoc_array(array $array): bool { return count(array_diff_key($array, array_keys(array_keys($array)))); }
  function is_assoc_arrayC(array $array): bool { // Appel de is_assoc_array commenté 
    print_r($array);
    $r = is_assoc_array($array);
    echo $r ? 'is_assoc_array' : '<b>! is_assoc_array</b>', "<br>\n";
    return $r;
  }

  if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de is_assoc_array 
    if (isset($_GET['test']) && ($_GET['test']=='is_assoc_array')) {
      echo "Test is_assoc_array<br>\n";
      is_assoc_arrayC([1, 5]);
      is_assoc_arrayC([1=>1, 5=>5]);
      is_assoc_arrayC([1=>1, 5=>5, 7=>7]);
      echo "FIN test is_assoc_array<br><br>\n";
    }
    $unitaryTests[] = 'is_assoc_array';
  }
}

/*PhpDoc: classes
name: JsonSch
title: class JsonSch - classe statique portant qqs méthodes statiques
methods:
*/
class JsonSch {
  static $predefs=null; // dictionnaire [ {predef} => {local} ] utilisé par self::predef()
  static $patterns=null; // dictionnaire [ {pattern} => {local} ] utilisé par self::predef()
  
  // remplace les chemins prédéfinis par leur équivalent local
  // utilise le fichier predef.yaml chargé dans self::$predefs et self::$patterns
  // si aucun remplacement, renvoie le path initial
  static function predef(string $path): ?string {
    //echo "predef(path=$path)<br>\n";
    if (self::$predefs === null) {
      if (($txt = @file_get_contents(__DIR__.'/predef.yaml')) === false)
        throw new Exception("ouverture impossible du fichier predef.yaml");
      try {
        $predefs = Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (Exception $e) {
        throw new Exception("Décodage Yaml du fichier predef.yaml incorrect: ".$e->getMessage());
      }
      self::$predefs = [];
      foreach ($predefs['predefs'] as $id => $predef) {
        self::$predefs[$id] = $predef['localPath'];
        if (isset($predef['aliases']))
          foreach ($predef['aliases'] as $alias)
            self::$predefs[$alias] = $predef['localPath'];
      }
      self::$patterns = $predefs['patterns'];
    }
    //echo (isset(self::$predefs[$path]) ? "remplacé par: ".__DIR__.'/'.self::$predefs[$path] : "absent"),"<br>\n";
    if (isset(self::$predefs[$path]))
      return __DIR__.'/'.self::$predefs[$path];
    foreach (self::$patterns as $pattern => $prefix) {
      if (preg_match("!$pattern!", $path)) {
        $path2 = preg_replace("!$pattern!", $prefix['localPath'], $path, 1);
        //echo "remplacé par: ".__DIR__.$path2,"<br>\n";
        return __DIR__.$path2;
      }
    }
    return $path;
  }
  
  // remplacement d'un objet { '$ref'=> {path} } pour fournir le contenu référencé ou une exception
  // le {path} ne doit pas être uniquement un #...
  // les chemins prédéfinis sont remplacés
  static function deref($def) {
    if (!is_array($def) || !isset($def['$ref']))
      return $def;
    $path = self::predef($def['$ref']);
    //echo "path après predef: $path<br>\n";
    if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)(#(.*))?$!', $path, $matches))
      throw new Exception("Chemin $path non compris dans JsonSch::deref()");
    $filepath = $matches[1]; // partie avant #
    $eltpath = isset($matches[4]) ? $matches[4] : ''; // partie après #
    $doc = self::file_get_contents($filepath);
    return self::subElement($doc, $eltpath);
  }
  
  // sélection d'un élément de l'array $content défini par le path $path
  // ERREUR je ne tiens pas compte des échappements définis dans https://tools.ietf.org/html/rfc6901
  static function subElement(array $content, string $path) {
    if (!$path)
      return $content;
    if (!preg_match('!^/([^/]+)(/.*)?$!', $path, $matches))
      throw new Exception("Erreur path '$path' mal formé dans subElement()");
    $first = $matches[1];
    $path = isset($matches[2]) ? $matches[2] : '';
    if (!isset($content[$first]))
      return null;
    elseif (!$path)
      return $content[$first];
    else
      return self::subElement($content[$first], $path);
  }
  static function subElementC(array $content, string $path) { // appel de subObject() commenté 
    echo "subElement(content=",json_encode($content),", path=$path)<br>\n";
    $result = self::subElement($content, $path);
    echo "returns: ",json_encode($result),"<br>\n";
    return $result;
  }
 
  // récupère le contenu d'un fichier JSON ou Yaml, renvoie une exception en cas d'erreur
  static function file_get_contents(string $path): array {
    //echo "jsonfile_get_contents(path=$path)<br>\n";
    if (($txt = @file_get_contents($path)) === false)
      throw new Exception("ouverture impossible du fichier $path");
    if ((substr($path, -5)=='.yaml') || (substr($path, -4)=='.yml')) {
      try {
        return Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (Exception $e) {
        throw new Exception("Décodage Yaml du fichier $path incorrect: ".$e->getMessage());
      }
    }
    elseif (substr($path, -5)=='.json') {
      if (($doc = json_decode($txt, true)) === null)
        throw new Exception("Décodage JSON du fichier $path incorrect: ".json_last_error_msg());
      return $doc;
    }
    else {
      try {
        return Yaml::parse($txt, Yaml::PARSE_DATETIME);
      }
      catch (Exception $e) {
        if (($doc = json_decode($txt, true)) !== null)
          return $doc;
        throw new Exception(
            "Décodage Yaml+JSON du fichier $path incorrect: ".$e->getMessage().'+'.json_last_error_msg());
      }
    }
  }
  
  // encode en JSON une valeur avec par défaut les options JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
  static function encode($val, int $options=0): string {
    $options |= JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    return json_encode($val, $options);
  }
};

/*PhpDoc: classes
name: JsonSchema
title: class JsonSchema - schéma JSON défini soit dans un fichier par un chemin soit par un array Php
methods:
doc: |
  La class JsonSchema correspond à un schéma JSON défini dans un fichier par un chemin
  de la forme {filePath}(#{eltPath})? où:
    - {filePath} identifie un fichier et peut être utilisé dans file_get_contents()
    - {eltPath} est le chemin d'un élément de la forme (/{elt})+ à l'intérieur du fichier
  Le fichier est soit un fichier json dont l'extension doit être .json,
  soit un fichier yaml dont l'extension doit être .yaml ou .yml
  Un JsonSchema peut aussi être défini par un array, dans ce cas si le champ $id n'est pas défini alors les chemins
  de fichier utilisés dans les références vers d'autres schémas ne doivent pas être définis en relatif
*/
class JsonSchema {
  const SCHEMAIDS = [ // liste des id acceptés pour le champ $schema
    'http://json-schema.org/schema#',
    'http://json-schema.org/draft-06/schema#',
    'http://json-schema.org/draft-07/schema#',
  ];
  private $verbose; // true pour afficher des commentaires
  private $filepath; // chemin du fichier contenant le schéma éventuellement null si inconnu
  private $def; // contenu du schéma comme array Php ou boolean
  private $elt; // objet JsonSchElt correspondant au schéma ou null si $def est booléen
  private $status; // objet JsonSchStatus contenant le statut issu de la création du schéma

  /*PhpDoc: methods
  name: __construct
  title: __construct($def, bool $verbose=false, ?JsonSchema $parent=null) - création d'un JsonSchema
  doc: |
    le premier paramètre est soit le chemin d'un fichier contenant l'objet JSON/Yaml,
      soit son contenu comme array Php ou comme booléen
    le second paramètre indique éventuellement si l'analyse doit être commentée
    le troisième paramètre contient éventuellement le schema père et n'est utilisé qu'en interne à la classe
  */
  function __construct($def, bool $verbose=false, ?JsonSchema $parent=null) {
    $this->verbose = $verbose;
    if ($verbose)
      echo "JsonSchema::_construct(def=",json_encode($def),", parent",$parent?'<>':'=',"null)<br>\n";
    $this->status = new JsonSchStatus;
    if (is_string($def)) { // le premier paramètre est le chemin du fichier contenant l'objet JSON
      $def = JsonSch::predef($def); // remplacement des chemins prédéfinis par leur équivalent local
      if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def, $matches))
        throw new Exception("Chemin $def non compris dans JsonSchema::__construct()");
      $filepath = $matches[1]; // partie avant #
      $eltpath = isset($matches[4]) ? $matches[4] : ''; // partie après #
      $this->filepath = $filepath;
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      if ((substr($filepath, 0, 7)=='http://') || (substr($filepath, 0, 1)=='/')) { // si chemin défini en absolu
        $def = JsonSch::file_get_contents($filepath);
      }
      else { // cas où le chemin du fichier est défini en relatif, utiliser alors le répertoire du schéma parent
        if (!$parent)
          throw new Exception("Ouverture de $filepath impossible sans schema parent");
        if (!($pfilepath = $parent->filepath))
          throw new Exception("Ouverture de $filepath impossible car le filepath du schema parent n'est pas défini");
        $pfiledir = dirname($pfilepath);
        $def = JsonSch::file_get_contents("$pfiledir/$filepath");
      }
      $eltDef = $eltpath ? JsonSch::subElement($def, $eltpath) : $def; // la définition de l'élément
    }
    elseif (is_array($def)) { // le premier paramètre est le contenu comme array Php
      $this->filepath = null;
      $eltDef = $def;
      if (isset($def['$id']) && preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def['$id'], $matches))
        $this->filepath = $matches[1];
      elseif (!isset($def['$ref']))
        $this->status->setWarning("Attention le schema ne comporte pas d'identifiant");
    }
    elseif (is_bool($def)) {
      $this->filepath = null;
      $this->def = $def;
      $this->elt = null;
      return;
    }
    else
      throw new Exception("Erreur paramètre incorrect dans la création d'un schéma");
    $this->def = $def;
    
    if (!isset($def['$ref']) &&
        (!isset($def['$schema']) || !in_array($def['$schema'], self::SCHEMAIDS)))
      $this->status->setWarning("Attention le schema ne comporte aucun des id. json-schema.org, draft-06 ou draft-07");
    if (isset($def['definitions'])) {
      foreach (array_keys($def['definitions']) as $defid)
        self::checkDefinition($defid, $def['definitions']);
    }
    $this->elt = new JsonSchElt($eltDef, $this, $verbose);
  }
  
  // définition du contenu du schéma sous la forme d'un array Php ou un boolean
  function def() { return $this->def; }
 
  // lance une exception si détecte une boucle dans les définitions ou une référence à une définition inexistante
  private static function checkDefinition(string $defid, array $defs, array $defPath=[]): void {
    //echo "checkLoopInDefinition(defid=$defid, defs=",json_encode($defs),")<br>\n";
    if (in_array($defid, $defPath))
      throw new Exception("boucle (".implode(', ', $defPath).", $defid) détectée");
    if (!isset($defs[$defid]))
      throw new Exception("Erreur, définition $defid inconnue");
    $def = $defs[$defid];
    ///echo "def="; print_r($def); echo "<br>\n";
    if (array_keys($def)[0]=='anyOf') {
      //echo "anyOf<br>\n";
      foreach ($def['anyOf'] as $childDef) {
        if (array_keys($childDef)[0]=='$ref') {
          //echo "childDef ref<br>\n";
          $ref = $childDef['$ref'];
          //echo "ref = $ref<br>\n";
          if (!preg_match('!^#/definitions/(.*)$!', $ref, $matches))
            throw new Exception("Référence '$ref' non comprise");
          $defid2 = $matches[1];
          self::checkDefinition($defid2, $defs, array_merge($defPath, [$defid]));
        }
      }
    }
  }
  
  /*PhpDoc: methods
  name: check
  title: "check($instance, array $options=[], string $id='', JsonSchStatus $status=null): JsonSchStatus - validation de conformité d'une instance au JsonSchema, renvoit un JsonSchStatus"
  doc: |
    Un check() prend un statut initial et le modifie pour le renvoyer à la fin
     - le premier paramètre est l'instance à valider comme valeur Php
     - le second paramètre indique éventuellement l'affichage à effectuer en fonction du résultat de la validation
       c'est un array qui peut comprendre les champs suivants:
        - showOk : chaine à afficher si ok
        - showWarnings : chaine à afficher si ok avec les Warnings
        - showKo : chaine à afficher si KO
        - showErrors : chaine à afficher si KO avec les erreurs
     - le troisième paramètre indique éventuellement un identificateur utilisé dans les erreurs
     - le quatrième paramètre fournit éventuellement un statut en entrée et n'est utilisé qu'en interne à la classe
  */
  function check($instance, array $options=[], string $id='', ?JsonSchStatus $status=null): JsonSchStatus {
    // au check initial, je clone le statut initial du schéma car je ne veux pas partager le statut entre check
    if (!$status)
      $status = clone $this->status;
    else
      $status->append($this->status);
    // cas particuliers des schémas booléens
    if (is_bool($this->def)) {
      if (!$this->def)
        $status->setError("Schema faux pour $id");
    }
    else
      $status = $this->elt->check($instance, $id, $status);
    // affichage éventuel du résultat en fonction des options
    if ($status->ok()) {
      if (isset($options['showOk']))
        echo $options['showOk'];
      if (isset($options['showWarnings']))
        $status->showWarnings($options['showWarnings']);
    }
    else {
      if (isset($options['showKo']))
        echo $options['showKo'];
      if (isset($options['showErrors']))
        $status->showErrors($options['showErrors']);
    }
    return $status;
  }
  
  /*PhpDoc: methods
  name: check
  title: "autoCheck($instance, array $options=[]): ?JsonSchStatus - valide la conformité d'une instance à son schéma défini par le champ jSchema"
  doc: |
    autoCheck() valide la conformité d'une instance à son schéma défini par le champ jSchema
    autoCheck() prend un ou 2 paramètres
     - le premier paramètre est soit l'instance à valider comme valeur Php, soit le chemin du fichier la contenant
     - le second paramètre indique éventuellement l'affichage à effectuer en fonction du résultat de la validation
       c'est un array qui peut comprendre les champs suivants:
        - showOk : chaine à afficher si ok
        - showWarnings : chaine à afficher si ok avec les Warnings
        - showKo : chaine à afficher si KO
        - showErrors : chaine à afficher si KO avec les erreurs
        - verbose : défini et vrai pour un appel verbeux, non défini ou faux pour un appel non verbeux
    autoCheck() renvoit un JsonSchStatus ou null si le schema n'est pas défini
  */
  static function autoCheck($instance, array $options=[]): ?JsonSchStatus {
    if (is_string($instance)) { // le premier paramètre est le chemin du fichier contenant l'objet JSON
      $instance = JsonSch::predef($instance); // remplacement des chemins prédéfinis par leur équivalent local
      if (!preg_match('!^([^#]+)(#(.*))?$!', $instance, $matches))
        throw new Exception("Chemin $instance non compris dans JsonSchema::autoCheck()");
      $filepath = $matches[1]; // partie avant #
      $eltpath = isset($matches[3]) ? $matches[3] : ''; // partie après #
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      $def = JsonSch::file_get_contents($filepath);
      $instance = $eltpath ? JsonSch::subElement($def, $eltpath) : $def; // la définition de l'élément
    }
    if (!isset($instance['jSchema']))
      return null;
    $schema = new JsonSchema($instance['jSchema'], isset($options['verbose']) && $options['verbose']);
    return $schema->check($instance, $options);
  }
};

/*PhpDoc: classes
name: JsonSchema
title: class JsonSchStatus - définit un statut de vérification de conformité d'une instance
methods:
doc: |
  La classe JsonSchStatus définit le statut d'une vérification de conformité d'une instance
  Un objet de cette classe est retourné par une vérification.
  La conformité de l'instance au schéma peut être testée et les erreurs et alertes peuvent être fournies
*/
class JsonSchStatus {
  private $warnings=[]; // liste des warnings
  private $errors=[]; // liste des erreurs dans l'instance
  
  // ajoute une erreur
  function setError(string $message): JsonSchStatus { $this->errors[] = $message; return $this; }
  
  /*PhpDoc: methods
  name: ok
  title: "ok(): bool - true ssi pas d'erreur"
  */
  function ok(): bool { return count($this->errors)==0; }
  
  /*PhpDoc: methods
  name: errors
  title: "errors(): array - retourne les erreurs"
  */
  function errors(): array { return $this->errors; }
  
  /*PhpDoc: methods
  name: showErrors
  title: "showErrors(): void - affiche les erreurs"
  */
  function showErrors(string $message=''): void {
    if ($this->errors)
      echo $message,'<pre><b>',Yaml::dump(['Errors'=>$this->errors], 999),"</b></pre>\n";
  }
  
  // ajoute un warning
  function setWarning(string $message): void { $this->warnings[] = $message; }
  
  /*PhpDoc: methods
  name: warnings
  title: "warnings(): array - retourne les alertes"
  */
  function warnings(): array { return $this->warnings; }
  
  /*PhpDoc: methods
  name: showWarnings
  title: "showWarnings(): void - affiche les warnings"
  */
  function showWarnings(string $message=''): void {
    echo $message;
    if ($this->warnings)
      echo '<pre><i>',Yaml::dump(['Warnings'=> $this->warnings], 999),"</i></pre>";
  }
  
  // ajoute à la fin du statut courant le statut en paramètre et renvoie le statut courant
  function append(JsonSchStatus $toAppend): JsonSchStatus {
    $this->warnings = array_merge($this->warnings, $toAppend->warnings);
    $this->errors = array_merge($this->errors, $toAppend->errors);
    return $this;
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe JsonSchema 
  if (isset($_GET['test']) && ($_GET['test']=='JsonSchema')) {
    echo "Test JsonSchema<br>\n";
    foreach ([['type'=> 'string'], ['type'=> 'number']] as $schemaDef) {
      $schema = new JsonSchema($schemaDef);
      $status = $schema->check('Test');
      if ($status->ok()) {
        echo "ok<br>\n";
        $status->showWarnings();
      }
      else
        $status->showErrors();
    }
    echo "FIN test JsonSchema<br><br>\n";
  }
  $unitaryTests[] = 'JsonSchema';
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de subElement 
  if (isset($_GET['test']) && ($_GET['test']=='subElement')) {
    echo "Test subElement<br>\n";
    $object = ['a'=>'a', 'b'=> ['c'=> 'bc', 'd'=> ['e'=> 'bde']]];
    JsonSch::subElementC($object, '/b');
    JsonSch::subElementC($object, '/b/d');
    JsonSch::subElementC($object, '/x');
    JsonSch::subElementC($object, '/a');
    echo "FIN test subElement<br><br>\n";
  }
  $unitaryTests[] = 'subElement';
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Menu des tests unitaires 
  echo "Tests unitaires:<ul>\n";
  foreach ($unitaryTests as $unitaryTest)
    echo "<li><a href='?test=$unitaryTest'>$unitaryTest</a>\n";
  die("</ul>\nFIN tests unitaires");
}

