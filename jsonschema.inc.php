<?php
/*PhpDoc:
name: jsonschema.inc.php
title: jsonschema.inc.php - conformité d'un objet Php à un schéma JSON
doc: |
  Définit la classe JsonSchema dont la méthode check() vérifie la conformité d'un objet Php au schéma JSON
  voir https://json-schema.org/understanding-json-schema/reference/index.html (draft-06)
  La classe est utilisée avec des valeurs Php
  3 types d'erreurs sont gérées:
    - une erreur de contenu de schéma génère une exception
    - une erreur de contenu d'instance produit une erreur et fait échouer la vérification
    - une alerte produit une alerte et ne fait pas échouer la vérification 
  il manque:
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
  7/1/2019:
    BUG trouvé dans l'utilisation d'une définition dans un oneOf,
    oneOf coupe le lien vers le schema racine pour éviter d'enregistrer les erreurs
    alors que les référence vers une définition a besoin de ce lien
    voir http://localhost/schema/?action=check&file=ex/route500
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

// sélection d'un sous-objet de l'objet $object défini par le path $path
if (!function_exists('subObject')) {
  function subObject(array $object, string $path) {
    if (!$path)
      return $object;
    if (!preg_match('!^/([^/]+)!', $path, $matches))
      throw new Exception("Erreur path '$path' mal formé dans subObject()");
    $first = $matches[1];
    $path = substr($path, strlen($first)+1);
    if (isset($object[$first])) {
      if (!$path)
        return $object[$first];
      else
        return subObject($object[$first], $path);
    }
    else {
      echo "Erreur le sous-objet $first n'existe pas dans subObject()<br>\n";
      return [];
    }
  }
  function subObjectC(array $object, string $path) { // appel de subObject() commenté 
    echo "subObject(",json_encode(['object'=>$object,'path'=>$path]),")<br>\n";
    $result = subObject($object, $path);
    echo "returns: ",json_encode($result),"<br>\n";
    return $result;
  }

  if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de subObject 
    if (isset($_GET['test']) && ($_GET['test']=='subObject')) {
      echo "Test subObject<br>\n";
      $object = ['a'=>'a', 'b'=> ['c'=> 'bc', 'd'=> ['e'=> 'bde']]];
      subObjectC($object, '/b');
      subObjectC($object, '/b/d');
      subObjectC($object, '/x');
      subObjectC($object, '/a');
      echo "FIN test subObject<br><br>\n";
    }
    $unitaryTests[] = 'subObject';
  }
}

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

// la classe JsonSchema correspond à la définition d'un schema JSON
class JsonSchema {
  const VERBOSE = false;
  private $schema=null; // le schema courant sous la forme d'un array
  private $root=null; // le schema initial comme objet Schema, utilisé pour rechercher les définitions
  private $warnings=[]; // liste des warnings (les warnings et les erreurs sont enregistrées dans le schema racine)
  private $errors=[]; // liste des erreurs dans l'instance
  
  // la création normale se fait à partir de la définition du schéma comme array Php,
  // $root est uniquement utilisé pour les appels récursifs
  function __construct(array $schema, ?JsonSchema $root=null) {
    if (!$root && !(isset($schema['$schema']) && ($schema['$schema']=='http://json-schema.org/draft-07/schema#')))
      $this->warnings[] = "Attention le schema ne comporte pas l'identifiant draft-07";
    if (isset($schema['definitions'])) {
      foreach ($schema['definitions'] as $defid => $def)
        self::checkDefinition($defid, $schema['definitions']);
    }
    $this->schema = $schema;
    $this->root = $root ? $root : $this;
  }
  
  // ajoute une erreur
  function setError(string $message): bool { $this->root->errors[] = $message; return false; }
  
  // retourne les erreurs
  function errors(): array { return $this->root->errors; }
  
  // affiche les erreurs
  function showErrors(): void {
    if ($this->root->errors)
      echo '<pre><b>',Yaml::dump(['Errors'=>$this->root->errors], 999),"</b></pre>\n";
  }
  
  // ajoute un warning
  function setWarning(string $message): void { $this->root->warnings[] = $message; }
  
  // afffiche les warnings
  function showWarnings(): void {
    if ($this->root->warnings)
      echo '<pre><i>',Yaml::dump(['Warnings'=> $this->root->warnings], 999),"</i></pre>";
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
  
  // le schema d'une des propriétés de l'object ou null
  private function schemaOfProperty(string $propname): ?JsonSchema {
    if (isset($this->schema['properties'][$propname]) && $this->schema['properties'][$propname])
      return new self($this->schema['properties'][$propname], $this->root);
    elseif (isset($this->schema['patternProperties'])) {
      foreach ($this->schema['patternProperties'] as $pattern => $property) {
        if (preg_match("!$pattern!", $propname)) {
          return new self($property, $this->root);
        }
      }
      $this->setWarning("No pattern matched by property '$propname' in JsonSchema::schemaOfProperty()");
    }
    return null;
  }
  
  // le schema des composants de l'array ou null
  private function schemaOfItem(): ?JsonSchema {
    return (!isset($this->schema['items'])) ? null : new self($this->schema['items'], $this->root);
  }
  
  // vérification que la valeur correspond au schema, le paramètre $id est utilisé pour afficher les erreurs
  function check($instance, string $id=''): bool {
    if (self::VERBOSE)
      echo "check(",json_encode(['instance'=> $instance, 'id'=>$id]),")",
           "@schema=",json_encode($this->schema),"<br><br>\n";
    if (!is_array($this->schema))
      throw new Exception("schema non défini pour $id comme array, schema=".json_encode($this->schema));
    elseif (isset($this->schema['$ref']))
      return $this->checkRef($id, $instance);
    elseif (isset($this->schema['anyOf']) || isset($this->schema['oneOf']))
      return $this->checkAnyOf($id, $instance);
    elseif (!isset($this->schema['type']))
      throw new Exception("schema[type] non défini pour $id, schema=".json_encode($this->schema));
    elseif (!is_string($this->schema['type'])) {
      if (!is_array($this->schema['type']))
        throw new Exception("schema[type]=".json_encode($this->schema['type'])
                            ." défini ni comme string ni comme list pour $id");
      $anyOf = [];
      foreach ($this->schema['type'] as $type) {
        if (!is_string($type))
          throw new Exception("type ".json_encode($type)." not a string");
        $anyOf[] = ['type'=> $type];
      }
      $schema = new JsonSchema(['anyOf'=> $anyOf], $this->root);
      return $schema->checkAnyOf($id, $instance);
    }
    elseif ($this->schema['type']=='object')
      return $this->checkObject($id, $instance);
    elseif ($this->schema['type']=='array')
      return $this->checkArray($id, $instance);
    elseif (in_array($this->schema['type'], ['number', 'integer']))
      return $this->checkNumberOrInteger($id, $instance);
    elseif ($this->schema['type']=='string')
      return $this->checkString($id, $instance);
    elseif ($this->schema['type']=='boolean')
      return $this->checkBoolean($id, $instance);
    elseif ($this->schema['type']=='null')
      return $this->checkNull($id, $instance);
    else
      throw new Exception("type ".json_encode($this->schema['type'])." non traité");
  }
  
  // traitement du cas où le schema est défini par une référence
  private function checkRef(string $id, $instance): bool {
    if (!preg_match('!^((http://[^/]+/[^#]+)|[^#]+)?(#(.*))?$!', $this->schema['$ref'], $matches))
      throw new Exception("Référence ".$this->schema['$ref']." non comprise dans JsonSchema::checkRef()");
    //echo "matches="; print_r($matches);
    $filepath = $matches[1];
    $eltpath = isset($matches[4]) ? $matches[4] : null;
    if ($filepath) {
      $doc = file_get_contents($filepath);
      if (substr($filepath, -5)=='.yaml')
        $schema = Yaml::parse($doc, Yaml::PARSE_DATETIME);
      elseif (substr($filepath, -5)=='.json')
        $schema = json_decode($doc, true);
    }
    else
      $schema = $this->root->schema;
    //echo "<pre>",Yaml::dump($doc, 999),"</pre>\n";
    if ($eltpath)
      $schema = subObject($schema, $eltpath);
    //echo "<pre>",Yaml::dump($schema, 999),"</pre>\n";
    if (!$schema) {
      //echo "<pre>schema=",Yaml::dump($schema, 999),"</pre>\n";
      throw new Exception("$filepath$eltpath ne correspond à un schéma dans JsonSchema::checkRef()");
    }
    $schema = new JsonSchema($schema, $this->root);
    return $schema->check($instance, $id);
  }
  
  // traitement du cas où le schema est défini par un anyOf ou un oneOf
  private function checkAnyOf(string $id, $instance): bool {
    if (self::VERBOSE)
      echo "checkAnyOf(",json_encode(['instance'=> $instance, 'id'=>$id]),")",
           "@schema=",json_encode($this->schema),"<br><br>\n";
    $anyOf = isset($this->schema['oneOf']) ? $this->schema['oneOf'] : $this->schema['anyOf'];
    foreach ($anyOf as $schemaDef) {
      $schema = new JsonSchema($schemaDef);
      if ($schema->check($instance, $id))
        return true;
    }
    return $this->setError("aucun schema anyOf pour $id");
  }
  
  // traitement du cas où le type indique que l'instance est un object
  private function checkObject(string $id, $instance): bool {
    if (!is_array($instance) || !is_assoc_array($instance))
      return $this->setError("$id !object");
    
    $status = true;
    // vérification que les propriétés obligatoires sont définies
    if (isset($this->schema['required'])) {
      foreach ($this->schema['required'] as $prop) {
        if (!array_key_exists($prop, $instance))
          $status = $this->setError("propriété $id.$prop absente");
      }
    }
    // vérification que les propriétés de l'objet sont définies dans le schéma si patternProperties non défini
    if (!isset($this->schema['patternProperties'])) {
      $properties = isset($this->schema['properties']) ? array_keys($this->schema['properties']) : [];
      if ($undef = array_diff(array_keys($instance), $properties))
        $this->setWarning("Attention: propriétés ".implode(', ',$undef)." de $id non définie(s) par le schéma");
    }
    // vérification des caractéristiques de chaque propriété
    foreach ($instance as $prop => $pvalue) {
      if ($schProp = $this->schemaOfProperty($prop)) {
        $status2 = $schProp->check($pvalue, "$id.$prop"); // nécessaire pour ne pas s'arrêter à la première erreur
        $status = $status && $status2;
      }
    }
    return $status;
  }
  
  // traitement du cas où le type indique que la valeur est un object
  private function checkArray(string $id, $instance): bool {
    if (self::VERBOSE)
      echo "checkArray(",json_encode(['id'=>$id, 'instance'=> $instance]),")",
           "@schema=",json_encode($this->schema),"<br><br>\n";
    
    if (!is_array($instance) || is_assoc_array($instance))
      return $this->setError("$id !array");
    $schOfItem = $this->schemaOfItem();
    if (!$schOfItem)
      return true;
    $status = true;
    foreach ($instance as $i => $elt) {
      $status2 = $schOfItem->check($elt, "$id.$i");
      $status = $status && $status2;
    }
    return $status;
  }
  
  // traitement du cas où le type indique que l'instance est un numérique ou un entier
  private function checkNumberOrInteger(string $id, $instance): bool {
    if (($this->schema['type']=='number') && (is_string($instance) || !is_numeric($instance)))
      return $this->setError("Erreur $id=".json_encode($instance)." !number");
    if (($this->schema['type']=='integer') && (is_string($instance) || !is_int($instance)))
      return $this->setError("Erreur $id=".json_encode($instance)." !integer");
    $status = true;
    if (isset($this->schema['minimum']) && ($instance < $this->schema['minimum']))
      $status = $this->setError("Erreur $id=$instance < minimim = ".$this->schema['minimum']);
    if (isset($this->schema['exclusiveMinimum']) && ($instance <= $this->schema['exclusiveMinimum']))
      $status = $this->setError("Erreur $id=$instance <= exclusiveMinimum = ".$this->schema['exclusiveMinimum']);
    if (isset($this->schema['maximum']) && ($instance > $this->schema['maximum']))
      $status = $this->setError("Erreur $id=$instance > maximum = ".$this->schema['maximum']);
    if (isset($this->schema['exclusiveMaximum']) && ($instance >= $this->schema['exclusiveMaximum']))
      $status = $this->setError("Erreur $id=$instance >= exclusiveMaximum = ".$this->schema['exclusiveMaximum']);
    return $status;
  }
  
  // traitement du cas où le type indique que l'instance est une chaine
  // les dates sont considérées comme des chaines de caractères
  private function checkString(string $id, $instance): bool {
    if (!is_string($instance) && !(is_object($instance) && (get_class($instance)=='DateTime')))
      return $this->setError("Erreur $id=".json_encode($instance)." !string");
    if (isset($this->schema['enum']) && !in_array($instance, $this->schema['enum']))
      return $this->setError("Erreur $id=\"$instance\" not in enum="
                             ."(\"".implode('","', $this->schema['enum'])."\")");
    if (isset($this->schema['const']) && ($instance <> $this->schema['const']))
      return $this->setError("Erreur $id=\"$instance\" <> const=\"".$this->schema['const']."\"");
    return true;
  }
  
  // traitement du cas où le type indique que l'instance est un booléen
  private function checkBoolean(string $id, $instance): bool {
    if (is_bool($instance))
      return true;
    else
      return $this->setError("Erreur $id.$prop=$instance !boolean");
  }
  
  // traitement du cas où le type indique que l'instance est null
  private function checkNull(string $id, $instance): bool {
    if (is_null($instance))
      return true;
    else
      return $this->setError("Erreur $id=".json_encode($instance)." !null");
  }
};

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Test unitaire de la classe JsonSchema 
  if (isset($_GET['test']) && ($_GET['test']=='JsonSchema')) {
    echo "Test JsonSchema<br>\n";
    $schema = new JsonSchema(['type'=> 'string']);
    if ($schema->check('Test')) {
      $schema->showWarnings();
      echo "ok<br>\n";
    }
    else
      $schema->showErrors();
    echo "FIN test JsonSchema<br><br>\n";
  }
  $unitaryTests[] = 'JsonSchema';
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) { // Menu des tests unitaires 
  echo "Tests unitaires:<ul>\n";
  foreach ($unitaryTests as $unitaryTest)
    echo "<li><a href='?test=$unitaryTest'>$unitaryTest</a>\n";
  die("</ul>\nFIN tests unitaires");
}

