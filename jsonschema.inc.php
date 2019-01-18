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
  Ce validateur implémente la spec http://json-schema.org/draft-07/schema# en totalité.
journal: |
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
  const SCHEMAIDS = [ // liste des id de schéma acceptés
    'http://json-schema.org/schema#',
    'http://json-schema.org/draft-06/schema#',
    'http://json-schema.org/draft-07/schema#',
  ];
  static $predefs=null; // dictionnaire [ {predef} => {local} ] utilisé par self::predef()
  static $patterns=null; // dictionnaire [ {pattern} => {local} ] utilisé par self::predef()
  private $verbose; // true pour afficher des commentaires
  private $filepath; // chemin du fichier contenant le schéma éventuellement null si inconnu
  private $def; // contenu du schéma comme array Php ou boolean
  private $elt; // objet JsonSchemaElt correspondant au schéma ou null si $def est booléen
  private $status; // objet JsonSchStatus contenant le statut issu de la création du schéma
  
  // remplace les chemins prédéfinis par leur équivalent local
  // utilise le fichier predef.yaml chargé dans self::$predefs
  static private function predef(string $path): ?string {
    //echo "predef(path=$path)<br>\n";
    if (self::$predefs === null) {
      if (($txt = @file_get_contents(__DIR__.'/predef.yaml')) === false)
        throw new Exception("ouverture impossible du fichier ");
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
    return null;
  }
  
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
      if ($path = self::predef($def)) // remplacement des chemins prédéfinis par leur équivalent local
        $def = $path;
      if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def, $matches))
        throw new Exception("Chemin $def non compris dans JsonSchema::__construct()");
      $filepath = $matches[1]; // partie avant #
      $eltpath = isset($matches[4]) ? $matches[4] : ''; // partie après #
      $this->filepath = $filepath;
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      if ((substr($filepath, 0, 7)=='http://') || (substr($filepath, 0, 1)=='/')) { // si chemin défini en absolu
        $def = self::jsonfile_get_contents($filepath);
      }
      else { // cas où le chemin du fichier est défini en relatif, utiliser alors le répertoire du schéma parent
        if (!$parent)
          throw new Exception("Ouverture de $filepath impossible sans schema parent");
        if (!($pfilepath = $parent->filepath))
          throw new Exception("Ouverture de $filepath impossible car le filepath du schema parent n'est pas défini");
        $pfiledir = dirname($pfilepath);
        $def = self::jsonfile_get_contents("$pfiledir/$filepath");
      }
      $eltDef = $eltpath ? self::subElement($def, $eltpath) : $def; // la définition de l'élément
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
    $this->elt = new JsonSchemaElt($eltDef, $this, $verbose);
  }
  
  // définition du contenu du schéma sous la forme d'un array Php ou un boolean
  function def() { return $this->def; }
  
  // sélection d'un élément de l'array $content défini par le path $path
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
  static function jsonfile_get_contents(string $path): array {
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
    else
      throw new Exception("Extension du fichier $path incorrecte");
  }
  
  // affiche le JSON d'une valeur avec par défaut les options JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
  static function json_encode($val, int $options=0): string {
    $options |= JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    return json_encode($val, $options);
  }
  
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
  title: "check($instance, string $id='', JsonSchStatus $status=null): JsonSchStatus - validation de conformité d'une instance à JsonSchema, renvoit un JsonSchStatus"
  doc: |
    Un check() prend un statut initial et le modifie pour le renvoyer à la fin
    le premier paramètre est l'instance à valider comme valeur Php
    le second paramètre indique éventuellement un identificateur utilisé dans les erreurs
    le troisième paramètre fournit éventuellement un statut en entrée et n'est utilisé qu'en interne à la classe
  */
  function check($instance, string $id='', ?JsonSchStatus $status=null): JsonSchStatus {
    // au check initial, je clone le statut initial du schéma car je ne veux pas partager le statut entre check
    if (!$status)
      $status = clone $this->status;
    else
      $status->append($this->status);
    // cas particuliers des schémas booléens
    if (is_bool($this->def))
      return $this->def ? $status : $status->setError("Schema faux pour $id");
    return $this->elt->check($instance, $id, $status);
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
  function showErrors(): void {
    if ($this->errors)
      echo '<pre><b>',Yaml::dump(['Errors'=>$this->errors], 999),"</b></pre>\n";
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
  function showWarnings(): void {
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

/*PhpDoc: classes
name: JsonSchemaElt
title: class JsonSchemaElt - classe interne utilisée par JsonSchema définissant un élémént d'un schema JSON
*/
class JsonSchemaElt {
  const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP'; // DateTimeInterface::RFC3339_EXTENDED
  private $verbose; // verbosité boolean
  private $def; // définition de l'élément courant du schema sous la forme d'un array ou d'un booléen Php
  private $schema; // l'objet schema contenant l'élément, indispensable pour retrouver ses définitions
  // et pour connaitre son répertoire courant en cas de référence relative
  
  function __construct($def, JsonSchema $schema, bool $verbose) {
    if (!is_array($def) && !is_bool($def)) {
      echo "JsonSchemaElt::__construct(def=",json_encode($this->def),")<br><br>\n";
      throw new Exception("TypeError: Argument def passed to JsonSchemaElt::__construct() must be of the type array or boolean");
    }
    $this->verbose = $verbose;
    $this->def = $def;
    $this->schema = $schema;
  }
  
  function __toString(): string { return json_encode($this->def, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
  
  function def(): array { return $this->def; }

  // le schema d'une des propriétés de l'object ou null si elle n'est pas définie
  private function schemaOfProperty(string $id, string $propname, JsonSchStatus $status): ?JsonSchemaElt {
    if (0 && $this->verbose)
      echo "schemaOfProperty(id=$id, propname=$propname)@def=$this<br><br>\n";
    if (isset($this->def['properties'][$propname]) && $this->def['properties'][$propname])
      return new self($this->def['properties'][$propname], $this->schema, $this->verbose);
    if (isset($this->def['patternProperties'])) {
      foreach ($this->def['patternProperties'] as $pattern => $property)
        if (preg_match("!$pattern!", $propname))
          return new self($property, $this->schema, $this->verbose);
    }
    if (isset($this->def['additionalProperties'])) {
      if ($this->def['additionalProperties'] === false) {
        $status->setError("Erreur propriété $id.$propname interdite");
        return null;
      }
      else
        return new self($this->def['additionalProperties'], $this->schema, $this->verbose);
    }
    $status->setWarning("Attention: la propriété '$id.$propname' ne correspond à aucun motif");
    return null;
  }
  
  // vérification que l'instance correspond à l'élément de schema
  // $id est utilisé pour afficher les erreurs, $status est le statut en entrée, le retour est le statut modifié
  function checkC($instance, string $id, JsonSchStatus $status): JsonSchStatus {
    if (!$this->verbose)
      return $this->checkI($instance, $id, $status);
    $s = new JsonSchStatus;
    $s = $this->checkI($instance, $id, $s);
    if (!$s->ok()) {
        echo "&lt;- check(instance=",json_encode($instance),", id=$id)@def=$this<br><br>\n";
      $s->showErrors();
    }
    $status->append($s);
    return $status;
  }
  function check($instance, string $id, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "check(instance=",json_encode($instance),", id=$id)@def=$this<br><br>\n";
    if (is_bool($this->def))
      return $this->def ? $status : $status->setError("Schema faux pour $id");
    if (!is_array($this->def))
      throw new Exception("schema non défini pour $id comme array, def=".json_encode($this->def));
    if (isset($this->def['$ref']))
      return $this->checkRef($id, $instance, $status);
    if (isset($this->def['anyOf']))
      return $this->checkAnyOf($id, $instance, $status);
    if (isset($this->def['oneOf']))
      return $this->checkOneOf($id, $instance, $status);
    if (isset($this->def['allOf']))
      return $this->checkAllOf($id, $instance, $status);
    if (isset($this->def['not']))
      return $this->checkNot($id, $instance, $status);
    
    $types = !isset($this->def['type']) ? [] :
        (is_string($this->def['type']) ? [$this->def['type']] : 
          (is_array($this->def['type']) ? $this->def['type'] : null));
    if ($types === null)
      throw new Exception("def[type]=".json_encode($this->def['type'])." ni string ni list pour $id");
    
    // vérifie la compatibilité entre le type indiqué par le schema et le type Php de l'instance
    if ($types)
      $status = $this->checkType($id, $types, $instance, $status);
    
    // vérifie les propriétés imposées
    if ((!$types || in_array('object', $types)))
      $status = $this->checkObject($id, $instance, $status);
    if (!$types || in_array('array', $types))
      $status = $this->checkArray($id, $instance, $status);
    if (!$types || array_intersect(['number', 'integer'], $types))
      $status = $this->checkNumberOrInteger($id, $instance, $status);
    if (!$types || in_array('string', $types))
      $status = $this->checkString($id, $instance, $status);
    return $status;
  }
   
  // traitement du cas où le schema est défini par un $ref
  private function checkRef(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkRef(id=$id, instance=",json_encode($instance),")@def=",json_encode($this->def),"<br><br>\n";
    $path = $this->def['$ref'];
    if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $path, $matches))
      throw new Exception("Chemin $path non compris dans JsonSchema::__construct()");
    $filepath = $matches[1];
    $eltpath = isset($matches[4]) ? $matches[4] : '';
    //echo "checkRef: filepath=$filepath, eltpath=$eltpath<br>\n";
    if (!$filepath) { // Si pas de filepath alors même fichier schéma
      $content = JsonSchema::subElement($this->schema->def(), $eltpath);
      $schemaElt = new self($content, $this->schema, $this->verbose);
      return $schemaElt->check($instance, $id, $status);
    }
    else {
      try { // Si filepath alors fichier schéma différent
        $schema = new JsonSchema($path, $this->verbose, $this->schema);
        return $schema->check($instance, $id, $status);
      } catch (Exception $e) {
        return $status->setError("Sur $id erreur ".$e->getMessage());
      }
    }
  }
  
  // traitement du cas où le schema est défini par un anyOf
  private function checkAnyOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkAnyOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    foreach ($this->def['anyOf'] as $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        return $status->append($status2);
    }
    return $status->setError("aucun schema anyOf pour $id");
  }
  
  // traitement du cas où le schema est défini par un oneOf
  private function checkOneOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkOneOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    $done = false;
    foreach ($this->def['oneOf'] as $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        if (!$done) {
          $status->append($status2);
          $done = true;
        }
        else
          return $status->setError("Plusieurs schema oneOf pour $id");
    }
    if ($done)
      return $status;
    else
      return $status->setError("aucun schema oneOf pour $id");
  }
  
  // traitement du cas où le schema est défini par un allOf
  private function checkAllOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkAllOf(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    foreach ($this->def['allOf'] as $no => $schemaDef) {
      $schema = new self($schemaDef, $this->schema, $this->verbose);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        $status->append($status2);
      else
        return $status->setError("schema $no de allOf non vérifié pour $id");
    }
    return $status;
  }
  
  // traitement du cas où le schema est défini par un not
  private function checkNot(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkNot(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    $schema = new self($this->def['not'], $this->schema, $this->verbose);
    $status2 = new JsonSchStatus;
    $status2 = $schema->check($instance, $id, $status2);
    if ($status2->ok())
      return $status->setError("Sous-schema de not ok pour $id");
    else
      return $status;
  }

  // vérifie la compatibilité entre le type indiqué par le schema et le type Php de l'instance
  private function checkType(string $id, array $types, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkType(id=$id, types=",json_encode($types),", instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (is_array($instance)) {
      if (!$instance) {
        if (!array_intersect(['object','array'], $types))
          $status->setError("$id ! ".implode('|',$types));
      }
      elseif (is_assoc_array($instance)) {
        if (!in_array('object', $types))
          $status->setError("$id ! ".implode('|',$types));
      }
      elseif (!in_array('array', $types))
        $status->setError("$id ! ".implode('|',$types));
    }
    
    if (is_int($instance) && !is_string($instance)) {
      if (!array_intersect(['integer','number'], $types))
        $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    }
    elseif (is_numeric($instance) && !is_string($instance)) {
      if (!in_array('number', $types))
        $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    }

    if (is_object($instance) && (get_class($instance)=='DateTime'))
      $instance = $instance->format(self::RFC3339_EXTENDED);
    if (is_string($instance) && !in_array('string', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    if (is_bool($instance) && !in_array('boolean', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    if (is_null($instance) && !in_array('null', $types))
      $status->setError("Erreur $id=".json_encode($instance)." ! ".implode('|',$types));
    
    return $status;
  }
  
  // traitement des propriétés liées aux objets
  private function checkObject(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkObject(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (!is_array($instance))
      return $status;
    
    // vérification que les propriétés obligatoires sont définies
    if (isset($this->def['required'])) {
      foreach ($this->def['required'] as $prop) {
        if (!array_key_exists($prop, $instance))
          $status->setError("propriété requise $id.$prop absente");
      }
    }
    
    // propertyNames définit le schéma que les propriétés doivent respecter
    if (isset($this->def['propertyNames'])) {
      $propSch = new self($this->def['propertyNames'], $this->schema, $this->verbose);
      foreach (array_keys($instance) as $propname) {
        $status = $propSch->check($propname, "$id.propertyNames.$propname", $status);
      }
    }
    // SinonSi ni patternProp ni additionalProp défini alors vérif que les prop de l'objet sont définies dans le schéma
    elseif (!isset($this->def['patternProperties']) && !isset($this->def['additionalProperties'])) {
      $properties = isset($this->def['properties']) ? array_keys($this->def['properties']) : [];
      if ($undef = array_diff(array_keys($instance), $properties))
        $status->setWarning("Attention: propriétés ".implode(', ',$undef)." de $id non définie(s) par le schéma");
    }
    
    // minProperties
    if (isset($this->def['minProperties']) && (count(array_keys($instance)) < $this->def['minProperties'])) {
      $nbProp = count(array_keys($instance));
      $minProperties = $this->def['minProperties'];
      $status->setError("objet $id a $nbProp propriétés < minProperties = $minProperties");
    }
    // maxProperties
    if (isset($this->def['maxProperties']) && (count(array_keys($instance)) > $this->def['maxProperties'])) {
      $nbProp = count(array_keys($instance));
      $maxProperties = $this->def['maxProperties'];
      $status->setError("objet $id a $nbProp propriétés > maxProperties = $maxProperties");
    }
    
    // vérification des caractéristiques de chaque propriété
    foreach ($instance as $prop => $pvalue) {
      if ($schProp = $this->schemaOfProperty($id, $prop, $status)) {
        $status = $schProp->check($pvalue, "$id.$prop", $status);
      }
    }
    
    // vérification des dépendances
    if (isset($this->def['dependencies']) && $this->def['dependencies']) {
      foreach ($this->def['dependencies'] as $propname => $dependency) {
        //echo "vérification de la dépendance sur $propname<br>\n";
        if (isset($instance[$propname])) { // alors la dépendance doit être vérifiée
          //echo "dependency=",json_encode($dependency),"<br>\n";
          if (!is_array($dependency))
            throw new Exception("Erreur dependency pour $id.$propname ni list ni assoc_array");
          elseif (!is_assoc_array($dependency)) { // property depedency
            //echo "vérification de la dépendance de propriété sur $propname<br>\n";
            foreach ($dependency as $dependentPropName)
              if (!isset($instance[$dependentPropName]))
                $status->setError("$id.$dependentPropName doit être défini car $id.$propname l'est");
          }
          else { // schema depedency
            //echo "vérification de la dépendance de schéma sur $propname<br>\n";
            $schProp = new self($dependency, $this->schema, $this->verbose);
            $status = $schProp->check($instance, $id, $status);
          }
        }
      }
    }
    return $status;
  }
  
  // traitement des propriétés d'array
  private function checkArray(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkArray(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    
    if (!is_array($instance))
      return $status;
    
    if (isset($this->def['minItems']) && (count($instance) < $this->def['minItems'])) {
      $nbre = count($instance);
      $minItems = $this->def['minItems'];
      $status = $status->setError("array $id contient $nbre items < minItems = $minItems");
    }
    if (isset($this->def['maxItems']) && (count($instance) > $this->def['maxItems'])) {
      $nbre = count($instance);
      $maxItems = $this->def['maxItems'];
      $status = $status->setError("array $id contient $nbre items > maxItems = $maxItems");
    }
    if (isset($this->def['contains'])) {
      $schOfElt = new self($this->def['contains'], $this->schema, $this->verbose);
      $oneOk = false;
      foreach ($instance as $i => $elt) {
        $status2 = $schOfElt->check($elt, "$id.$i", new JsonSchStatus);
        if ($status2->ok()) {
          $status->append($status2);
          $oneOk = true;
          break;
        }
      }
      if (!$oneOk)
        $status->setError("aucun élément de $id ne vérifie contains");
    }
    if (isset($this->def['uniqueItems']) && $this->def['uniqueItems']) {
      if (count(array_unique($instance)) <> count($instance))
        $status->setError("array $id ne vérifie pas uniqueItems");
    }
    if (!isset($this->def['items']))
      return $status;
    if (is_bool($this->def['items']))
      return $this->def['items'] ? $status : $status->setError("items faux pour $id");
    if (!is_array($this->def['items']))
      throw new Exception("items devrait être un objet, un array ou un booléen pour $id");
    if (!is_assoc_array($this->def['items']))
      return $this->checkTuple($id, $instance, $status);
    $schOfItem = new self($this->def['items'], $this->schema, $this->verbose);
    foreach ($instance as $i => $elt)
      $status = $schOfItem->check($elt, "$id.$i", $status);
    return $status;
  }
  
  // traitement du cas où le type indique que la valeur est un object
  private function checkTuple(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkTuple(id=$id, instance=",json_encode($instance),")@def=$this<br><br>\n";
    foreach ($this->def['items'] as $i => $defElt) {
      if (isset($instance[$i])) {
        $schOfElt = new self($defElt, $this->schema, $this->verbose);
        $status = $schOfElt->check($instance[$i], "$id.$i", $status);
      }
    }
    if (isset($this->def['additionalItems']) && is_bool($this->def['additionalItems'])) {
      if (count(array_keys($instance)) > count(array_keys($this->def['items'])))
        return $status->setError("additionalItems forbiden for $id");
    }
    if (isset($this->def['additionalItems']) && is_array($this->def['additionalItems'])) {
      $addItemsSchema = new self($this->def['additionalItems'], $this->schema, $this->verbose);
      foreach ($instance as $i => $elt)
        if (!isset($this->def['items'][$i]))
          $staus = $addItemsSchema->check($elt, "$id.$i", $status);
    }
    return $status;
  }

  // traitement du cas où le type indique que l'instance est un numérique ou un entier
  private function checkNumberOrInteger(string $id, $number, JsonSchStatus $status): JsonSchStatus {
    if (!is_numeric($number))
      return $status;
    if (isset($this->def['minimum']) && ($number < $this->def['minimum']))
      $status = $status->setError("Erreur $id=$number < minimim = ".$this->def['minimum']);
    if (isset($this->def['exclusiveMinimum']) && ($number <= $this->def['exclusiveMinimum']))
      $status = $status->setError("Erreur $id=$number <= exclusiveMinimum = ".$this->def['exclusiveMinimum']);
    if (isset($this->def['maximum']) && ($number > $this->def['maximum']))
      $status = $status->setError("Erreur $id=$number > maximum = ".$this->def['maximum']);
    if (isset($this->def['exclusiveMaximum']) && ($number >= $this->def['exclusiveMaximum']))
      $status = $status->setError("Erreur $id=$number >= exclusiveMaximum = ".$this->def['exclusiveMaximum']);
    if (isset($this->def['multipleOf']) && !self::hasNoFractionalPart($number/$this->def['multipleOf']))
      $status = $status->setError("Erreur $id=$number non multiple de ".$this->def['multipleOf']);
    return $status;
  }
  
  // teste l'absence de partie fractionaire du nombre passé en paramètre, en pratique elle doit être très faible
  static private function hasNoFractionalPart($f): bool { return abs($f - floor($f)) < 1e-15; }
  
  // traitement du cas où le type indique que l'instance est une chaine ou une date
  private function checkString(string $id, $string, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkString(id=$id, instance=",json_encode($string),")@def=$this<br><br>\n";
    if (is_array($string))
      return $status;
    if (is_object($string) && (get_class($string)=='DateTime'))
      $string = $string->format(self::RFC3339_EXTENDED);
    if (isset($this->def['enum']) && !in_array($string, $this->def['enum']))
      $status->setError("Erreur $id=\"$string\" not in enum=".JsonSchema::json_encode($this->def['enum']));
    if (isset($this->def['const']) && ($string <> $this->def['const']))
      $status->setError("Erreur $id=\"$string\" <> const=\"".$this->def['const']."\"");
    if (!is_string($string))
      return $status;
    if (isset($this->def['minLength']) && (strlen($string) < $this->def['minLength']))
      $status->setError("length($string)=".strlen($string)." < minLength=".$this->def['minLength']);
    if (isset($this->def['maxLength']) && (strlen($string) > $this->def['maxLength']))
      $status->setError("length($string)=".strlen($string)." > maxLength=".$this->def['maxLength']);
    if (isset($this->def['pattern'])) {
      $pattern = $this->def['pattern'];
      if (!preg_match("!$pattern!", $string))
        $status->setError("$string don't match $pattern");
    }
    if (isset($this->def['format']))
      $status = $this->checkStringFormat($id, $string, $status);
    return $status;
  }
  
  // test des formats, certains motifs sont à améliorer
  private function checkStringFormat(string $id, string $string, JsonSchStatus $status): JsonSchStatus {
    $knownFormats = [
      'date-time'=> '^\d\d\d\d-\d\d-\d\dT\d\d:\d\d(:\d\d(\.\d+)?)?(([-+]\d\d:\d\d)|Z)$', // RFC 3339, section 5.6.
      'email'=> '^[-a-zA-Z0-9_\.]+@[-a-zA-Z0-9_\.]+$', // A vérifier - email address, see RFC 5322, section 3.4.1.
      'hostname'=> '^[-a-zA-Z0-9\.]+$', // Internet host name, see RFC 1034, section 3.1.
      'ipv4'=> '^\d+\.\d+\.\d+\.\d+(/\d+)?$', // IPv4 address, as defined in RFC 2673, section 3.2.
      'ipv6'=> '^[:0-9a-fA-F]+$', // IPv6 address, as defined in RFC 2373, section 2.2.
      'uri'=> '^(([^:/?#]+):)(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI, according to RFC3986.
      'uri-reference'=> '^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI Reference, RFC3986, section 4.1.
      'json-pointer'=> '^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(/[^/]+)*)?$', // A JSON Pointer, RFC6901.
      'uri-template'=> '^(([^:/?#]+):)(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$', // A URI Template, RFC6570.
    ];
    $format = $this->def['format'];
    if (!isset($knownFormats[$format])) {
      $status->setWarning("format $format inconnu pour $id");
      return $status;
    }
    $pattern = $knownFormats[$format];
    if (!preg_match("!$pattern!", $string))
      $status->setError("$string don't match $format");
    return $status;
  }
};


if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe JsonSchema 
  if (isset($_GET['test']) && ($_GET['test']=='JsonSchema')) {
    echo "Test JsonSchema<br>\n";
    foreach ([['type'=> 'string'], ['type'=> 'number']] as $schemaDef) {
      $schema = new JsonSchema($schemaDef);
      $status = $schema->check('Test');
      if ($status->ok()) {
        $status->showWarnings();
        echo "ok<br>\n";
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
    JsonSchema::subElementC($object, '/b');
    JsonSchema::subElementC($object, '/b/d');
    JsonSchema::subElementC($object, '/x');
    JsonSchema::subElementC($object, '/a');
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

