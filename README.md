## Package Php de validation de conformité à un schéma JSON

Ce package Php implémente un validateur de conformité d'un document à un [schéma JSON](http://json-schema.org/).  
Ce validateur est défini dans la classe JsonSchema définie dans le fichier jsonschema.inc.php  

Pour l'utiliser, 2 possibilités:

  - soit appeler la méthode statique JsonSchema::autoCheck() qui prend en paramètre une instance
    et retourne un JsonSchStatus.
  - soit en 2 étapes:
  
    - commencer par initialiser un objet schema par new JsonSchema($schemaSrc) où $schemaSrc doit être soit:

      - un array Php correspondant au schéma JSON chargé en Php
      - une chaine contenant le chemin d'un fichier JSON contenant le schéma.
    
    - puis réaliser le test de conformité est réalisé par un appel $schema->check($document)  
      où $document doit être soit un document JSON chargé comme array Php, soit un chemin vers un fichier contenant
      le document.  

Le test renvoie un objet $status de la classe JsonSchStatus qui contient le résultat de la validation.  
$status->ok() renvoie true ssi le document est conforme.  
Si le document n'est pas conforme la méthode $status->showErrors() affiche les erreurs de conformité.  
Dans tous les cas, la méthode $status->showWarnings() affiche les alertes.  

Ce validateur peut être utilisé avec des fichiers Yaml.
Il suffit de charger le fichier comme array Php ou d'utiliser le chemin du fichier Yaml.

3 types d'erreurs sont gérées:

  - une structuration non prévue de schéma génère une exception
  - une non conformité d'une instance à un schéma fait échouer la vérification
  - une alerte peut être produite dans certains cas sans faire échouer la vérification

Lors d'une validation d'un document, si le schéma est conforme au méta-schéma alors la génération d'une exception
correspond à un bug du code.  
Ce validateur implémente la spec http://json-schema.org/draft-06/schema# en totalité.

Le validateur peut aussi être appelé interactivement en appelant le fichier index.php
qui offre aussi les fonctionnalités suivantes:

  - vérification de la validité des pointeurs JSON d'un fichier Yaml,
  - conversion interactive entre les formats Yaml, JSON et Php.

