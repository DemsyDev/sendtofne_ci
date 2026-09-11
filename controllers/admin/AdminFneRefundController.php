<?php
require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneSender.php';

/*
class AdminFneRefundController extends ModuleAdminController
{
    public function initContent()
    {
        parent::initContent();

        $idOrder = (int)Tools::getValue('id_order');

        // 🔒 ANTI-DOUBLE AVOIR FNE
        $alreadyRefunded = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM '._DB_PREFIX_.'fne_log
             WHERE id_order='.(int)$idOrder.' AND type="REFUND"'
        );

        if ($alreadyRefunded > 0) {
            // Avoir déjà créé → on bloque silencieusement
            Tools::redirectAdmin($this->context->link->getAdminLink(
                'AdminOrders',
                true,
                [],
                ['id_order' => $idOrder, 'vieworder' => 1]
            ));
        }

        $order = new Order($idOrder);

        // Facture FNE d’origine
        $row = Db::getInstance()->getRow(
            'SELECT fne_reference FROM '._DB_PREFIX_.'fne_log
             WHERE id_order='.(int)$idOrder.' AND type="INVOICE"'
        );

        if (!$row || empty($row['fne_reference'])) {
            die('Aucune facture FNE trouvée pour cette commande');
        }

        // 🔹 Items obligatoires pour l’avoir
        $items = [];
        foreach ($order->getProducts() as $p) {
            $items[] = [
                "reference" => $p['product_reference'] ?: ('P'.$p['product_id']),
                "description" => $p['product_name'],
                "quantity" => (float)$p['product_quantity'],
                "amount" => round((float)$p['unit_price_tax_excl'], 2),
                "discount" => 0,
                "taxes" => ["TVAC"],
                "customTaxes" => [],
                "measurementUnit" => "pcs"
            ];
        }

        if (empty($items)) {
            die('Impossible de créer un avoir sans articles');
        }

        // Envoi de l’avoir FNE
        $sender = new FneSender();
        $res = $sender->sendRefund($row['fne_reference'], $items);

        // Log
        Db::getInstance()->insert('fne_log', [
            'id_order' => $idOrder,
            'type' => 'REFUND',
            'fne_reference' => 'AVOIR',
            'response_code' => pSQL($res['code']),
            'response_body' => pSQL($res['body']),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        Tools::redirectAdmin($this->context->link->getAdminLink(
            'AdminOrders',
            true,
            [],
            ['id_order' => $idOrder, 'vieworder' => 1]
        ));
    }
}

*/

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
        $invoice = $invoiceLog['invoice'];

        if (empty($invoice['items']) || !is_array($invoice['items'])) {
            die('Lignes FNE introuvables pour l’avoir');
        }

        if (
            empty($invoiceLog['invoice']) ||
            empty($invoiceLog['invoice']['id'])
        ) {
            var_dump($invoiceLog['invoice']);
            die('ID technique FNE introuvable pour la facture');
        }

        $invoiceId = $invoiceLog['invoice']['id']; // ✅ LA BONNE VALEUR

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

        //$sender = new FneSender();
        // $res = $sender->sendRefund($invoiceId, $items);
        $res = $sender->sendRefund($invoice['id'], $items);

        $json = json_decode($res['body'], true);

        // 🔴 LA VRAIE RÉFÉRENCE DE L’AVOIR FNE
        $refundReference = $json['reference'] ?? 'AVOIR';

        Db::getInstance()->insert('fne_log', [
            'id_order'      => (int)$idOrder,
            'type'          => 'REFUND',
            'fne_reference' => pSQL($refundReference), // ✅ RÉFÉRENCE FISCALE AVOIR
            'response_code' => (string)$res['code'],
            'response_body' => pSQL(json_encode($json)),
            'created_at'    => date('Y-m-d H:i:s')
        ]);



        // Db::getInstance()->insert('fne_log', [
        //     'id_order'      => $idOrder,
        //     'type'          => 'REFUND',
        //     'fne_reference' => 'AVOIR',
        //     'response_code' => (string)$res['code'],
        //     'response_body' => pSQL($res['body']),
        //     'created_at'    => date('Y-m-d H:i:s')
        // ]);

        Tools::redirectAdmin($this->context->link->getAdminLink(
            'AdminOrders', true, [], ['id_order'=>$idOrder,'vieworder'=>1]
        ));
    }
}

