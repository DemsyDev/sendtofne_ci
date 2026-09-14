<?php
require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneSender.php';

/**
 * Contrôleur Back Office chargé de l'envoi d'une facture
 * vers la Facture Normalisée Électronique (FNE).
 *
 * Le contrôleur reçoit depuis l'interface :
 *
 * - le mode de paiement FNE ;
 * - le template FNE.
 * 
 * Ces paramètres concernent uniquement l'émission
 * de la facture.
 *
 * La génération d'un avoir est traitée par un contrôleur
 * distinct et ne dépend pas de ces valeurs.
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
     * Les valeurs PAYMENT_METHOD et TEMPLATE sont reçues
     * par POST puis contrôlées contre les valeurs officiellement
     * autorisées par la FNE.
     *
     * Une facture n'est considérée comme envoyée avec succès
     * que lorsque l'API retourne le code HTTP 200 ou 201.
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

        /* L'émission d'une facture doit obligatoirement provenir du formulaire POST. */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            die( 'Méthode de requête invalide pour l’envoi de la facture.' );
        }

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
         * Vérifie si une opération INVOICE REUSSIE existe déjà
         * dans l'historique FNE pour cette commande.
         *
         * (UNIQUEMENT si succès)
         * 
         * Seuls les envois ayant abouti avec 200 ou 201
         * bloquent une nouvelle émission.
         */
        $exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM "._DB_PREFIX_."fne_log
            WHERE id_order=".$idOrder." 
            AND type='INVOICE' 
            AND response_code IN('200','201')"
        );

        if ($exists > 0) {
            // Bloque toute double facturation FNE
            Tools::redirectAdmin($this->context->link->getAdminLink(
                'AdminOrders', true, [], ['id_order'=>$idOrder,'vieworder'=>1]
            ));
        }
        //../ 🔒 Anti-doublon FNE

        /*
         * ========================================================
         * PAYMENT_METHOD
         * ========================================================
         */

        $paymentMethod = Tools::getValue('fne_payment_method');

        $allowedPaymentMethods = [
            'cash',
            'card',
            'check',
            'mobile-money',
            'transfer',
            'deferred'
        ];

        if ( !is_string($paymentMethod) || !in_array($paymentMethod, $allowedPaymentMethods, true) ) {
            die( 'Mode de paiement FNE invalide.');
        }

        /*
         * ========================================================
         * TEMPLATE
         * ========================================================
         */

        $template = Tools::getValue('fne_template');

        $allowedTemplates = [
            'B2B',
            'B2F',
            'B2G',
            'B2C'
        ];

        if ( !is_string($template) || !in_array($template, $allowedTemplates, true) ) {
            die('Template FNE invalide.');
        }

        /*
         * ========================================================
         * ENVOI FNE
         * ========================================================
         */

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
        $res = $sender->sendInvoice($order, $paymentMethod, $template);

        /*
         * Décodage du corps JSON retourné par l'API FNE.
         */
        $json = json_decode($res['body'], true);
        // var_dump($json);die();

        /*
         * Si la réponse n'est pas un JSON valide,
         * nous conservons tout de même la réponse brute
         * dans le journal technique.
         */
        if (!is_array($json)) {
            $json = [
                'raw_response' => $res['body']
            ];
        }

        /*
         * ========================================================
         * JOURNALISATION
         * ========================================================
         */

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
         * ========================================================
         * ÉTAT PRESTASHOP
         * ========================================================
         *
         * Le changement d'état intervient uniquement
         * après un succès fiscal FNE : 200 ou 201.
         */

        /*
         * Si l'API retourne actuellement le code HTTP 200 ou 201,
         * la commande passe à l'état PrestaShop « Envoyé à la FNE ».
         */
        // if ($res['code']==200) {
        if (in_array((string)$res['code'], ['200', '201'], true)) {
            // $state = (int)Configuration::get('FNE_ORDER_STATE_ID');
            $stateId = (int)Configuration::get('FNE_ORDER_STATE_ID');

            if ($stateId > 0) {
                $history = new OrderHistory();

                $history->id_order = $idOrder;

                $history->changeIdOrderState($stateId, $order);

                $history->add();
            }

            // $h = new OrderHistory();
            // $h->id_order=$idOrder;
            // $h->changeIdOrderState($state,$order);
            // $h->add();
        }

        /* Retour vers la page de détail de la commande. */
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders',true,[],['id_order'=>$idOrder,'vieworder'=>1]));
    }
}
?>