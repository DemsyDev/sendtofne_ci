<?php
require_once _PS_MODULE_DIR_.'sendtofne_ci/classes/FneSender.php';

class AdminSendToFneCiController extends ModuleAdminController
{
    public function initContent()
    {
        parent::initContent();

        // 🔒 Anti-doublon FNE
        // $id = (int)Tools::getValue('id_order');
        $order = new Order((int)Tools::getValue('id_order'));

        if (!Validate::isLoadedObject($order)) {
            die('Commande invalide');
        }

        $idOrder = (int)$order->id;


        $exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM "._DB_PREFIX_."fne_log
            WHERE id_order=".$id." AND type='INVOICE'"
        );

        if ($exists > 0) {
            // Bloque toute double facturation FNE
            Tools::redirectAdmin($this->context->link->getAdminLink(
                'AdminOrders', true, [], ['id_order'=>$id,'vieworder'=>1]
            ));
        }
        //../ 🔒 Anti-doublon FNE


        $id = (int)Tools::getValue('id_order');
        $order = new Order($id);
        $sender = new FneSender();
        $res = $sender->sendInvoice($order);
        $json = json_decode($res['body'], true);

        /* Bloc d’insertion log facture */
        Db::getInstance()->insert('fne_log', [
            'id_order'       => $idOrder,
            'type'           => 'INVOICE',
            'fne_reference'  => pSQL($json['reference'] ?? ''),
            'response_code'  => (string)$res['code'],
            'response_body'  => pSQL(json_encode($json)), // 🔴 on stocke tout
            'created_at'     => date('Y-m-d H:i:s')
        ]);


        if ($res['code']==200) {
            $state = (int)Configuration::get('FNE_ORDER_STATE_ID');
            $h = new OrderHistory();
            $h->id_order=$id;
            $h->changeIdOrderState($state,$order);
            $h->add();
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders',true,[],['id_order'=>$id,'vieworder'=>1]));
    }
}
?>