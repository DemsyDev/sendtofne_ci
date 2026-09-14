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

