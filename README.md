## Package Php de validation de conformité à un schéma JSON

Ce package Php implémente un validateur de conformité d'un document à un [schéma JSON](http://json-schema.org/).  
Ce validateur est défini dans la classe JsonSchema définie dans le fichier jsonschema.inc.php  
Pour l'utiliser, commencer par initialiser un schema par new JsonSchema($schemaSrc)  
où $schemaSrc doit être soit:

  - un array Php correspondant au schéma JSON chargé en Php
  - une chaine contenant le chemin d'un fichier Yaml ou JSON contenant le schéma.
   
Le test de conformité est réalisé par un appel $schema->check($document)  
où $document doit être soit un document JSON chargé comme array Php, soit un chemin vers un fichier contenant
le document.  

Le test renvoie un objet $status de la classe JsonSchStatus qui contient le résultat du contrôle.  
$status->ok() renvoie true si le document est conforme et false sinon.  
Si le document n'est pas conforme la méthode $status->showErrors() affiche les erreurs de conformité.  
Dans tous les cas, la méthode $status->showWarnings() affiche les alertes.  

Ce validateur peut être utilisé avec des fichiers Yaml.
Il suffit de charger le fichier comme array Php ou d'utiliser le chemin du fichier Yaml.

Cette implémentation est fondée sur la définition http://json-schema.org/draft-06/schema#
Elle est complète.
