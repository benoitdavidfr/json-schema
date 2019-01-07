## Package Php de contrôle de conformité à un schema JSON

Ce package Php implémente un contrôleur de conformité d'un document à un schema JSON.  
Ce contrôleur est défini dans la classe JsonSchema définie dans le fichier jsonschema.inc.php  
Pour l'utiliser, commencer par initialiser un schema par new JsonSchema($schemaSrc)  
où $schemaSrc doit être un array Php correspondant à un schéma JSON chargé en Php.  
Le test de conformité est réalisé par un appel $schema->check($document)  
où $document est un document JSON chargé comme array Php.  
