<?php
require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneSender.php';

/**
 * Contrôleur Back Office chargé de l'envoi d'une facture
 * vers la Facture Normalisée Électronique (FNE).
 *
 * Ce contrôleur est appelé depuis l'interface du détail
 * d'une commande PrestaShop lorsqu'un utilisateur déclenche
 * l'action « Envoi vers FNE ».
 *
 * Son rôle est de :
 *
 * - récupérer et valider la commande concernée ;
 * - empêcher une double facturation FNE ;
 * - transmettre la commande à FneSender ;
 * - décoder la réponse de l'API FNE ;
 * - enregistrer le résultat de l'opération dans fne_log ;
 * - mettre à jour l'état de la commande PrestaShop en cas
 *   de succès FNE ;
 * - rediriger l'utilisateur vers le détail de la commande.
 *
 * La construction du payload FNE et la communication avec
 * l'API sont déléguées à la classe FneSender.
 */
class AdminSendToFneCiController extends ModuleAdminController
{
    /**
     * Traite la demande d'envoi d'une facture vers la FNE.
     *
     * Le processus est exécuté dans l'ordre suivant :
     *
     * 1. Initialisation du contenu du contrôleur parent.
     * 2. Récupération de l'identifiant de la commande.
     * 3. Chargement et validation de la commande PrestaShop.
     * 4. Vérification de l'existence d'une facture FNE déjà enregistrée.
     * 5. Envoi de la commande à l'API FNE via FneSender.
     * 6. Décodage de la réponse JSON retournée par l'API.
     * 7. Enregistrement de l'opération dans fne_log.
     * 8. Si la réponse HTTP est 200, changement de l'état
     *    de la commande vers l'état « Envoyé à la FNE ».
     * 9. Redirection vers la page de détail de la commande.
     *
     * L'identifiant de commande utilisé pour les opérations
     * suivantes est récupéré à partir de l'objet Order validé
     * afin d'éviter d'utiliser une variable non initialisée
     * ou une valeur non validée.
     *
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        // 🔒 Anti-doublon FNE
        //
        // Récupération et validation de la commande avant
        // toute opération métier.
        // $id = (int)Tools::getValue('id_order');
        $order = new Order((int)Tools::getValue('id_order'));

        if (!Validate::isLoadedObject($order)) {
            die('Commande invalide');
        }

        $idOrder = (int)$order->id;

        /*
         * Vérifie si une opération INVOICE existe déjà
         * dans l'historique FNE pour cette commande.
         *
         * La présence d'une entrée INVOICE entraîne actuellement
         * le blocage d'une nouvelle tentative d'envoi.
         */
        $exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM "._DB_PREFIX_."fne_log
            WHERE id_order=".$idOrder." AND type='INVOICE'"
        );

        if ($exists > 0) {
            // Bloque toute double facturation FNE
            Tools::redirectAdmin($this->context->link->getAdminLink(
                'AdminOrders', true, [], ['id_order'=>$idOrder,'vieworder'=>1]
            ));
        }
        //../ 🔒 Anti-doublon FNE


        // $id = (int)Tools::getValue('id_order');
        // $order = new Order($id);

        /*
         * Création du composant chargé de construire et
         * transmettre la facture à l'API FNE.
         */
        $sender = new FneSender();

        /*
         * Envoi de la commande vers la FNE.
         *
         * FneSender retourne notamment le code HTTP de la réponse
         * ainsi que son corps JSON.
         */
        $res = $sender->sendInvoice($order);

        /*
         * Décodage du corps JSON retourné par l'API FNE.
         */
        $json = json_decode($res['body'], true);

        /* Bloc d’insertion log facture */
        /*
         * Enregistrement de l'opération dans l'historique FNE.
         *
         * Le corps de réponse complet est conservé dans fne_log
         * afin de permettre la consultation et l'exploitation
         * ultérieure de la réponse FNE.
         */
        Db::getInstance()->insert('fne_log', [
            'id_order'       => $idOrder,
            'type'           => 'INVOICE',
            'fne_reference'  => pSQL($json['reference'] ?? ''),
            'response_code'  => (string)$res['code'],
            'response_body'  => pSQL(json_encode($json)), // 🔴 on stocke tout
            'created_at'     => date('Y-m-d H:i:s')
        ]);

        /*
         * Si l'API retourne actuellement le code HTTP 200,
         * la commande passe à l'état PrestaShop « Envoyé à la FNE ».
         */
        if ($res['code']==200) {
            $state = (int)Configuration::get('FNE_ORDER_STATE_ID');
            $h = new OrderHistory();
            $h->id_order=$id;
            $h->changeIdOrderState($state,$order);
            $h->add();
        }

        /* Retour vers la page de détail de la commande. */
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders',true,[],['id_order'=>$id,'vieworder'=>1]));
    }
}
?>