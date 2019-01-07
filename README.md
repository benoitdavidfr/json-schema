## Package Php de contrôle de conformité à un schéma JSON

Ce package Php implémente un contrôleur de conformité d'un document à un [schéma JSON](http://json-schema.org/).  
Ce contrôleur est défini dans la classe JsonSchema définie dans le fichier jsonschema.inc.php  
Pour l'utiliser, commencer par initialiser un schema par new JsonSchema($schemaSrc)  
où $schemaSrc doit être un array Php correspondant au schéma JSON chargé en Php.  
Le test de conformité est réalisé par un appel $schema->check($document)  
où $document est un document JSON chargé comme array Php.  

Le test renvoie true si le document est conforme et false sinon.  
Si le document n'est pas conforme la méthode showErrors() affiche les erreurs de conformité.  
Dans tous les cas, la méthode showWarnings() affiche les alertes.  

Ce contrôleur peut être utilisé avec des fichiers Yaml.
Il suffit de charger le fichier comme array Php.

Cette implémentation est fondée sur la définition http://json-schema.org/draft-07/schema#
Elle est partielle.
Les mécanismes suivants ne sont pas implémentés:
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
