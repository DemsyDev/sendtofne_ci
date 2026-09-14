## Gestion technique des états de commande FNE

Le module crée et utilise deux états de commande PrestaShop dédiés au suivi
du cycle de traitement fiscal FNE.

### État 1 — Facture FNE réussie

Nom : **Envoyé à la FNE**

Cet état est utilisé lorsqu'une facture PrestaShop a été transmise avec succès à la FNE.

Configuration fonctionnelle :

logable      = true
invoice      = false
paid         = false
shipped      = false
delivery     = false
send_email   = false

L'identifiant de cet état est stocké dans : FNE_ORDER_STATE_ID

Le contrôleur d'envoi de facture récupère cet identifiant dans la configuration
et l'applique à la commande uniquement lorsque la réponse FNE est un succès
(200 ou 201).

### État 2 — Facture FNE puis avoir FNE réussi

Nom : **Envoyé vers FNE - Puis Avoir FNE : Annulée**

Cet état représente une commande dont la facture FNE a été créée puis annulée
fiscalement par la génération réussie d'un avoir FNE.

L'état est volontairement distinct de l'état natif PrestaShop Annulée, afin
de permettre d'identifier explicitement les commandes ayant suivi ce cycle
fiscal FNE.

Il doit néanmoins reproduire le comportement fonctionnel d'un état
d'annulation.

Configuration :
logable       = false
invoice       = false
paid          = false
shipped       = false
delivery      = false
send_email    = false
pdf_invoice   = false
pdf_delivery  = false

L'identifiant de cet état est stocké dans : FNE_REFUND_ORDER_STATE_ID

Le contrôleur de génération d'avoir récupère cet identifiant et l'applique
uniquement lorsque l'API FNE confirme avec succès la génération de l'avoir
(code HTTP 200 ou 201).

## Relation entre traitement FNE et état PrestaShop

Le changement d'état est effectué après l'enregistrement de l'opération dans fne_log.

Le flux technique est donc :
Appel API FNE
     ↓
Réponse FNE
     ↓
Enregistrement dans fne_log
     ↓
Vérification du code HTTP
     ↓
200 / 201 ?
     │
     ├── NON → aucun changement d'état
     │
     └── OUI
          ↓
     changement d'état PrestaShop
          ↓
     redirection vers la commande

Cette séquence permet de conserver dans fne_log la trace de l'opération
avant de modifier l'état de la commande.

## Principe d'installation et d'évolution

La création des états FNE relève de la responsabilité de FneInstaller.

La classe principale du module ne doit pas contenir directement toute la
logique de création des états.

À terme, l'architecture doit suivre le principe :
Sendtofne_ci
      │
      └── FneInstaller
             ├── installation
             ├── désinstallation
             ├── base de données
             ├── configuration
             ├── hooks
             └── états de commande

L'ajout du deuxième état FNE sur une installation existante doit être réalisé
par une procédure de migration/upgrade.

Une évolution du module ne doit pas nécessiter la désinstallation puis la
réinstallation du module.

En particulier, l'ajout ou la modification d'un état de commande ne doit pas
entraîner la suppression de fne_log.

## Conservation de l'historique FNE

La table fne_log constitue une donnée historique durable du module.

Elle n'est pas soumise à une contrainte de clé étrangère vers
ps_orders.

Le champ : *id_order* constitue une référence historique vers la commande PrestaShop concernée.

Cette conception permet notamment :

* de conserver l'historique FNE même après le nettoyage de commandes anciennes ;
* de conserver les données FNE indépendamment des données commerciales opérationnelles ;
* de restaurer ou importer l'historique FNE indépendamment d'une sauvegarde de commandes donnée ;
* d'éviter qu'une contrainte SQL empêche la maintenance ou la restauration sélective de la base.

L'historique FNE ne doit donc pas être considéré comme une simple donnée
temporaire liée au cycle de vie de ps_orders.