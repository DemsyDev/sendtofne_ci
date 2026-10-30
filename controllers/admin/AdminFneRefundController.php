<?php
require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneSender.php';

class AdminFneRefundController extends ModuleAdminController
{
    public function initContent()
    {
        parent::initContent();

        $idOrder = (int)Tools::getValue('id_order');

        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            die('Commande invalide');
        }


        // 🔒 Anti-double avoir (UNIQUEMENT si succès)
        $alreadyRefunded = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM '._DB_PREFIX_.'fne_log
             WHERE id_order='.$idOrder.'
             AND type="REFUND"
             AND response_code IN ("200","201")'
        );

        if ($alreadyRefunded > 0) {
            Tools::redirectAdmin($this->context->link->getAdminLink(
                'AdminOrders', true, [], ['id_order'=>$idOrder,'vieworder'=>1]
            ));
        }

        // Facture FNE originale
        $row = Db::getInstance()->getRow(
            'SELECT response_body
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            AND type="INVOICE"
            AND response_code IN ("200","201")
            ORDER BY created_at DESC'
        );

        if (!$row) {
            die('Facture FNE introuvable');
        }

        $invoiceLog = json_decode($row['response_body'], true);

        /** validation défensive de la structure invoice avant exploitation. */
        if ( !is_array($invoiceLog) || !isset($invoiceLog['invoice']) || !is_array($invoiceLog['invoice']) ) 
        {
            die('Réponse FNE invalide : données de facture introuvables.');
        }

        $invoice = $invoiceLog['invoice'];

        if (empty($invoice['items']) || !is_array($invoice['items'])) {
            die('Lignes FNE introuvables pour l’avoir');
        }

        // if ( empty($invoiceLog['invoice']) || empty($invoiceLog['invoice']['id']) ) {
        //     var_dump($invoiceLog['invoice']);
        //     die('ID technique FNE introuvable pour la facture');
        // }

        if (empty($invoice['id'])) {
            die('ID technique FNE introuvable pour la facture');
        }

        // $invoiceId = $invoiceLog['invoice']['id']; // ✅ LA BONNE VALEUR

        // var_dump($invoiceLog['invoice']);

        $sender = new FneSender();

        $items = [];

        foreach ($invoice['items'] as $item) {
            if (empty($item['id'])) {
                continue;
            }

            $items[] = [
                'id'       => (string)$item['id'],          // ✅ STRING, ID FNE
                'quantity' => (float)$item['quantity'],     // ou quantité à annuler
            ];
        }

        if (empty($items)) {
            die('Impossible de créer un avoir sans lignes FNE');
        }

        // >>> AJOUT POINT 9 : Vérification de l'état spécifique avant l'avoir FNE

        require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneInstaller.php';

        $installer = new FneInstaller($this->module);

        if (!$installer->ensureFneRefundOrderState()) {

            $this->context->cookie->fne_error =
                'Impossible de créer ou de vérifier l’état de commande dédié aux avoirs FNE. Aucun avoir n’a été envoyé.';

            $this->context->cookie->fne_error_order_id = (int)$idOrder;

            $this->context->cookie->write();

            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, [],
                [
                    'id_order' => $idOrder,
                    'vieworder' => 1,
                ]
            ));

            return;
        }

        $stateId = (int)Configuration::get('FNE_REFUND_ORDER_STATE_ID');

        if ($stateId <= 0) {

            $this->context->cookie->fne_error =
                'L’identifiant de l’état de commande dédié aux avoirs FNE est introuvable. Aucun avoir n’a été envoyé.';

            $this->context->cookie->fne_error_order_id = (int)$idOrder;

            $this->context->cookie->write();

            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders',true, [],
                [
                    'id_order' => $idOrder,
                    'vieworder' => 1,
                ]
            ));

            return;
        }

        // <<< FIN AJOUT POINT 9

        //$sender = new FneSender();
        // $res = $sender->sendRefund($invoiceId, $items);
        // Génération de l'avoir FNE
        $res = $sender->sendRefund($invoice['id'], $items);

        $json = json_decode($res['body'], true);

        // 🔴 LA VRAIE RÉFÉRENCE DE L’AVOIR FNE
        $refundReference = $json['reference'] ?? 'AVOIR';

        /*
         * Journalisation de la tentative d'avoir.
         *
         * Le log est enregistré avant le changement d'état
         * afin que l'historique technique FNE conserve la trace
         * de l'opération ayant conduit à l'annulation de la commande.
         */
        Db::getInstance()->insert('fne_log', [
            'id_order'      => (int)$idOrder,
            'type'          => 'REFUND',
            'fne_reference' => pSQL($refundReference), // ✅ RÉFÉRENCE FISCALE AVOIR
            'response_code' => (string)$res['code'],
            'response_body' => pSQL(json_encode($json)),
            'created_at'    => date('Y-m-d H:i:s')
        ]);

        /*
         * Changement d'état uniquement après une génération
         * réussie de l'avoir FNE.
         *
         * Les codes 200 et 201 sont considérés comme des succès
         * FNE conformément au fonctionnement retenu par le module.
         */
        if (in_array((string)$res['code'], ['200', '201'], true)) {
            // Nous n'avons plus besoin de vérifier l'identifiant à cet endroit : il a déjà été vérifié avant l'appel à l'API.
            // $stateId = (int)Configuration::get('FNE_REFUND_ORDER_STATE_ID');

            // if ($stateId > 0) {
            //     $history = new OrderHistory();
            //     $history->id_order = $idOrder;

            //     $history->changeIdOrderState($stateId, $order);

            //     $history->add();
            // }
            // ../ // Nous n'avons plus besoin de vérifier l'identifiant... - A SUPPRIMER

            $history = new OrderHistory();
            $history->id_order = $idOrder;

            $history->changeIdOrderState($stateId, $order);

            $history->add();
        }

        Tools::redirectAdmin($this->context->link->getAdminLink(
            'AdminOrders', true, [], ['id_order'=>$idOrder,'vieworder'=>1]
        ));
    }
}

