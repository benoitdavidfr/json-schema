<?php
/*PhpDoc:
name: jsonschema.inc.php
title: jsonschema.inc.php - conformité d'un objet Php à un schéma JSON
classes:
doc: |
  Pour vérifier la conformité d'un objet Php à un schéma, il faut:
    - créer un objet JsonSchema en fournissant le contenu du schema
    - appeler sur cet objet la méthode check avec l'objet Php à vérifier
    - analyser le statut retourné (classe JsonSch Status) par cette vérification
  voir https://json-schema.org/understanding-json-schema/reference/index.html (draft-06)
  La classe est utilisée avec des valeurs Php
  3 types d'erreurs sont gérées:
    - une erreur de contenu de schéma génère une exception
    - une erreur de contenu d'instance produit une erreur et fait échouer la vérification
    - une alerte produit une alerte et ne fait pas échouer la vérification
  Normalement, en vérifiant au préalable que le schéma est conforme au méta-schéma, il ne devrait jamais y avoir 
  d'exception
  Il manque:
    - additionalProperties (object)
    - propertyNames (object)
    - minProperties (object)
    - maxProperties (object)
    - dependencies (object)
    - contains (array)
    - Tuple validation (array)
    - minItems (array)
    - maxItems (array)
    - uniqueItems (array)
    - allOf (schema)
    - not (schema)
    - length (string)
    - pattern (string)
    - format (string)
    - multiple (number)
journal: |
  11/1/2018:
    Correction d'un bug
  10/1/2018:
    Correction du bug du 9/1
   9/1/2018:
    Réécriture complète en 3 classes: schéma JSON, élément d'un schéma et statut d'une vérification
    BUG dans la vérification d'un schéma par le méta-schéma
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
doc: |
  La class JsonSchema correspond à un schéma JSON défini dans un fichier par un chemin
  de la forme {filePath}(#{eltPath})? où:
    - {filePath} identifie un fichier et peut être utilisé dans file_get_contents()
    - {eltPath} est le chemin d'un élément de la forme (/{elt})+ à l'intérieur du fichier
  Le fichier est soit un fichier json dont l'extension doit êytre .json,
  soit un fichier yaml dont l'extension doit être .yaml ou .yml
  Un JsonSchema peut aussi être défini par un array, dans ce cas si le champ $id n'est pas défini alors les chemins
  de fichier utilisés dans les références vers d'autres schémas ne doivent pas être définis en relatif
*/
class JsonSchema {
  private $verbose; // true pour afficher des commentaires
  private $filepath; // chemin du fichier contenant le schéma
  private $def; // contenu du schéma comme array Php ou boolean
  private $elt; // objet JsonSchemaElt correspondant au schéma
  private $status; // objet JsonSchStatus définissant le statut issu de la création du schéma
  
  // le premier paramètre est soit le chemin de l'objet JSON dans un fichier, soit son contenu comme array Php
  // le second paramètre contient éventuellement le schema père
  function __construct($def, ?JsonSchema $parent=null, bool $verbose=false) {
    $this->verbose = $verbose;
    if ($verbose)
      echo "JsonSchema::_construct(def=",json_encode($def),", parent",$parent?'<>':'=',"null)<br>\n";
    $this->status = new JsonSchStatus;
    if (is_string($def)) { // le premier paramètre est le chemin de l'objet JSON dans un fichier
      if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $def, $matches))
        throw new Exception("Chemin $def non compris dans JsonSchema::__construct()");
      $filepath = $matches[1];
      $eltpath = isset($matches[4]) ? $matches[4] : '';
      $this->filepath = $filepath;
      //echo "filepath=$filepath, eltpath=$eltpath<br>\n";
      if ((substr($filepath, 0, 7)=='http://') || (substr($filepath, 0, 1)=='/')) { // si chemin défini en absolu
        $def = self::jsonfile_get_contents($filepath);
      }
      else { // cas où le chemin du fichier est défini en relatif, utiliser alors le répertoire du schéma parent
        if (!$parent)
          throw new Exception("Ouverture de $filepath impossible sans parent");
        if (!($pfilepath = $parent->filepath))
          throw new Exception("Ouverture de $filepath impossible car le filepath du parent n'est pas défini");
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
      else
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
    
    if (!isset($def['$schema']) || ($def['$schema']<>'http://json-schema.org/draft-07/schema#'))
      $this->status->setWarning("Attention le schema ne comporte pas l'identifiant draft-07");
    if (isset($def['definitions'])) {
      foreach (array_keys($def['definitions']) as $defid)
        self::checkDefinition($defid, $def['definitions']);
    }
    $this->elt = new JsonSchemaElt($eltDef, $this);
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
  
  // lance une exception si détecte dans la définition une boucle ou une référence à une définition inexistante
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
  
  // Un check() prend un statut initial et le modifie pour le renvoyer à la fin
  function check($instance, string $id='', JsonSchStatus $status=null): JsonSchStatus {
    // au check initial, je clone le statut initial du schéma car je ne veux pas partager le statut entre check
    if (!$status)
      $status = clone $this->status;
    // cas particuliers des schémas booléens
    if (is_bool($this->def)) {
      if ($this->def)
        return $status;
      else
        return $status->setError("Schema faux n'acceptant aucune instance");
    }
    return $this->elt->check($instance, $id, $status);
  }
};

/*PhpDoc: classes
name: JsonSchema
title: class JsonSchStatus - définit un statut de vérification de conformité d'une instance
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
  
  // ok ssi pas d'erreur
  function ok(): bool { return count($this->errors)==0; }
  
  // retourne les erreurs
  function errors(): array { return $this->errors; }
  
  // affiche les erreurs
  function showErrors(): void {
    if ($this->errors)
      echo '<pre><b>',Yaml::dump(['Errors'=>$this->errors], 999),"</b></pre>\n";
  }
  
  // ajoute un warning
  function setWarning(string $message): void { $this->warnings[] = $message; }
  
  // retourne les alertes
  function warnings(): array { return $this->warnings; }
  
  // afffiche les warnings
  function showWarnings(): void {
    if ($this->warnings)
      echo '<pre><i>',Yaml::dump(['Warnings'=> $this->warnings], 999),"</i></pre>";
  }
  
  // ajoute à la fin du statut courant le statut en paramètre, renvoie le statut courant
  function append(JsonSchStatus $toAppend): JsonSchStatus {
    $this->warnings = array_merge($this->warnings, $toAppend->warnings);
    $this->errors = array_merge($this->errors, $toAppend->errors);
    return $this;
  }
};

/*PhpDoc: classes
name: JsonSchemaElt
title: class JsonSchemaElt - définit un élémént d'un schema JSON
*/
class JsonSchemaElt {
  private $verbose;
  private $def; // définition sous la forme d'un array Php de l'élément courant du schema
  private $schema; // l'objet schema contenant l'élément, indispensable pour retrouver ses définitions
  // et pour connaitre son répertoire courant en cas de référence relative
  
  function __construct(array $def, JsonSchema $schema, bool $verbose=false) {
    $this->verbose = $verbose;
    $this->def = $def;
    $this->schema = $schema;
  }
  
  function def(): array { return $this->def; }
      
  // le schema d'une des propriétés de l'object ou null
  private function schemaOfProperty(string $id, string $propname, JsonSchStatus $status): ?JsonSchemaElt {
    if (isset($this->def['properties'][$propname]) && $this->def['properties'][$propname])
      return new self($this->def['properties'][$propname], $this->schema);
    elseif (isset($this->def['patternProperties'])) {
      foreach ($this->def['patternProperties'] as $pattern => $property) {
        if (preg_match("!$pattern!", $propname)) {
          return new self($property, $this->schema);
        }
      }
      $status->setWarning("Attention: la propriété '$id.$propname' ne correspond à aucun motif");
    }
    return null;
  }
  
  // vérification que l'instance correspond à l'élément de schema
  // $id est utilisé pour afficher les erreurs, $status est le statut en entrée, le retour est le statut modifié
  function check($instance, string $id, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "check(instance=",json_encode($instance),", id=$id)@def=",json_encode($this->def),"<br><br>\n";
    if (!is_array($this->def))
      throw new Exception("schema non défini pour $id comme array, def=".json_encode($this->def));
    elseif (isset($this->def['$ref']))
      return $this->checkRef($id, $instance, $status);
    elseif (isset($this->def['anyOf']) || isset($this->def['oneOf']))
      return $this->checkAnyOf($id, $instance, $status);
    elseif (!isset($this->def['type']))
      //throw new Exception("schema[type] non défini pour $id, schema=".json_encode($this->schema));
      return $status; // je considère qu'il s'agit d'un schéma vide et le test est alors validé
    elseif (!is_string($this->def['type'])) {
      if (!is_array($this->def['type']))
        throw new Exception("def[type]=".json_encode($this->def['type'])." ni string ni list pour $id");
      $anyOf = [];
      foreach ($this->def['type'] as $type) {
        if (!is_string($type))
          throw new Exception("type ".json_encode($type)." not a string");
        $elt = ['type'=> $type];
        if (($type == 'object') && (isset($this->def['properties'])))
          $elt['properties'] = $this->def['properties'];
        $anyOf[] = $elt;
      }
      $elt = new self(['anyOf'=> $anyOf], $this->schema);
      return $elt->checkAnyOf($id, $instance, $status);
    }
    elseif ($this->def['type']=='object')
      return $this->checkObject($id, $instance, $status);
    elseif ($this->def['type']=='array')
      return $this->checkArray($id, $instance, $status);
    elseif (in_array($this->def['type'], ['number', 'integer']))
      return $this->checkNumberOrInteger($id, $instance, $status);
    elseif ($this->def['type']=='string')
      return $this->checkString($id, $instance, $status);
    elseif ($this->def['type']=='boolean')
      return $this->checkBoolean($id, $instance, $status);
    elseif ($this->def['type']=='null')
      return $this->checkNull($id, $instance, $status);
    else
      throw new Exception("type ".json_encode($this->def['type'])." non traité");
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
      $schemaElt = new JsonSchemaElt($content, $this->schema);
      return $schemaElt->check($instance, $id, $status);
    }
    else {
      try {
        $schema = new JsonSchema($path, $this->schema);
        return $schema->check($instance, $id);
      } catch (Exception $e) {
        return $status->setError("Sur $id erreur ".$e->getMessage());
      }
    }
  }
  
  // traitement du cas où le schema est défini par un anyOf ou un oneOf
  private function checkAnyOf(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkAnyOf(id=$id, instance=",json_encode($instance),")@def=",json_encode($this->def),"<br><br>\n";
    $anyOf = isset($this->def['oneOf']) ? $this->def['oneOf'] : $this->def['anyOf'];
    foreach ($anyOf as $schemaDef) {
      $schema = new self($schemaDef, $this->schema);
      $status2 = new JsonSchStatus;
      $status2 = $schema->check($instance, $id, $status2);
      if ($status2->ok())
        return $status->append($status2);
    }
    return $status->setError("aucun schema anyOf pour $id");
  }
  
  // traitement du cas où le type indique que l'instance est un object
  private function checkObject(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if (!is_array($instance) || ($instance && !is_assoc_array($instance))) // Attention, la liste vide est un objet
      return $status->setError("$id !object");
    
    // vérification que les propriétés obligatoires sont définies
    if (isset($this->def['required'])) {
      foreach ($this->def['required'] as $prop) {
        if (!array_key_exists($prop, $instance))
          $status->setError("propriété $id.$prop absente");
      }
    }
    // si patternProperties non défini alors vérification que les propriétés de l'objet sont définies dans le schéma
    if (!isset($this->def['patternProperties'])) {
      $properties = isset($this->def['properties']) ? array_keys($this->def['properties']) : [];
      if ($undef = array_diff(array_keys($instance), $properties))
        $status->setWarning("Attention: propriétés ".implode(', ',$undef)." de $id non définie(s) par le schéma");
    }
    // vérification des caractéristiques de chaque propriété
    foreach ($instance as $prop => $pvalue) {
      if ($schProp = $this->schemaOfProperty($id, $prop, $status)) {
        $status = $schProp->check($pvalue, "$id.$prop", $status);
      }
    }
    return $status;
  }
  
  // traitement du cas où le type indique que la valeur est un object
  private function checkArray(string $id, $instance, JsonSchStatus $status): JsonSchStatus {
    if ($this->verbose)
      echo "checkArray(id=$id, instance=",json_encode($instance),")@def=",json_encode($this->def),"<br><br>\n";
    
    if (!is_array($instance) || is_assoc_array($instance))
      return $status->setError("$id !array");
    if (!isset($this->def['items']))
      return $status;
    if (is_bool($this->def['items']) && $this->def['items'])
      return $status;
    if (!is_array($this->def['items']) || !is_assoc_array($this->def['items']))
      throw new Exception("items devrait être un objet ou un booléen");
    $schOfItem = new self($this->def['items'], $this->schema);
    foreach ($instance as $i => $elt) {
      $status = $schOfItem->check($elt, "$id.$i", $status);
    }
    return $status;
  }
  
  // traitement du cas où le type indique que l'instance est un numérique ou un entier
  private function checkNumberOrInteger(string $id, $number, JsonSchStatus $status): JsonSchStatus {
    if (($this->def['type']=='number') && (is_string($number) || !is_numeric($number)))
      return $status->setError("Erreur $id=".json_encode($number)." !number");
    if (($this->def['type']=='integer') && (is_string($number) || !is_int($number)))
      return $status->setError("Erreur $id=".json_encode($number)." !integer");
    if (isset($this->def['minimum']) && ($number < $this->def['minimum']))
      $status = $status->setError("Erreur $id=$number < minimim = ".$this->def['minimum']);
    if (isset($this->def['exclusiveMinimum']) && ($number <= $this->def['exclusiveMinimum']))
      $status = $status->setError("Erreur $id=$number <= exclusiveMinimum = ".$this->def['exclusiveMinimum']);
    if (isset($this->def['maximum']) && ($number > $this->def['maximum']))
      $status = $status->setError("Erreur $id=$number > maximum = ".$this->def['maximum']);
    if (isset($this->def['exclusiveMaximum']) && ($number >= $this->def['exclusiveMaximum']))
      $status = $status->setError("Erreur $id=$number >= exclusiveMaximum = ".$this->def['exclusiveMaximum']);
    return $status;
  }
  
  // traitement du cas où le type indique que l'instance est une chaine
  // les dates sont considérées comme des chaines de caractères
  private function checkString(string $id, $string, JsonSchStatus $status): JsonSchStatus {
    if (!is_string($string) && !(is_object($string) && (get_class($string)=='DateTime')))
      return $status->setError("Erreur $id=".json_encode($string)." !string");
    if (isset($this->def['enum']) && !in_array($string, $this->def['enum']))
      return $status->setError("Erreur $id=\"$string\" not in enum="
                             ."(\"".implode('","', $this->def['enum'])."\")");
    if (isset($this->def['const']) && ($string <> $this->def['const']))
      return $status->setError("Erreur $id=\"$string\" <> const=\"".$this->def['const']."\"");
    return $status;
  }
  
  // traitement du cas où le type indique que l'instance est un booléen
  private function checkBoolean(string $id, $bool, JsonSchStatus $status): JsonSchStatus {
    return is_bool($bool) ? $status : $status->setError("Erreur $id=".json_encode($bool)." !boolean");
  }
  
  // traitement du cas où le type indique que l'instance est null
  private function checkNull(string $id, $null, JsonSchStatus $status): JsonSchStatus {
    return is_null($null) ? $status : $status->setError("Erreur $id=".json_encode($null)." !null");
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

