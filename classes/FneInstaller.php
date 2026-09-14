<?php

/**
 * Gestion de l'installation, de la désinstallation
 * et des évolutions du module SendToFNE CI.
 *
 * Cette classe centralise les opérations structurelles
 * du module afin de ne pas surcharger la classe principale
 * Sendtofne_ci.
 *
 * Les évolutions du module doivent être réalisées par
 * migration/upgrade et non par désinstallation/réinstallation,
 * afin de préserver les données historiques contenues
 * dans fne_log.
 */
class FneInstaller
{
    /**
     * Module auquel l'installateur est rattaché.
     *
     * @var Module
     */
    private $module;

    /**
     * Initialise l'installateur.
     *
     * @param Module $module Instance du module SendToFNE CI.
     */
    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * Installe l'état de commande utilisé après
     * la génération réussie d'un avoir FNE.
     *
     * Cet état représente une commande dont la facture FNE
     * a été générée puis annulée fiscalement par un avoir FNE.
     *
     * - la facture FNE a été créée ;
     * - l'avoir FNE a ensuite été créé avec succès ;
     * - la commande doit désormais être considérée
     *   comme annulée dans PrestaShop.
     *
     * L'état est donc configuré "volontairement" selon la logique
     * d'un état d'annulation :
     *
     * - logable = false ;
     * - paid = false ;
     * - shipped = false ;
     * - delivery = false ;
     * - invoice = false ;
     * - pdf_invoice = false ;
     * - pdf_delivery = false ;
     * - send_email = false.
     * 
     * - la commande n'est pas considérée comme valide ;
     * - elle n'est pas considérée comme payée ;
     * - elle n'est pas considérée comme expédiée ;
     * - aucune facture PrestaShop n'est générée ;
     * - aucun e-mail automatique n'est envoyé.
     *
     * Si l'état existe déjà dans la configuration,
     * aucune nouvelle création n'est effectuée.
     *
     * @return bool
     *         True si l'état existe déjà ou a été créé
     *         avec succès, false en cas d'échec.
     */
    public function installFneRefundOrderState()
    {
        if (Configuration::get('FNE_REFUND_ORDER_STATE_ID')) {
            return true;
        }

        $state = new OrderState();

        foreach (Language::getLanguages() as $lang) {
            $state->name[$lang['id_lang']] =
                'Envoyé vers FNE - Puis Avoir FNE : Annulée';
        }

        $state->color = '#2C3E50';

        $state->logable = false;
        $state->send_email = false;
        $state->invoice = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return false;
        }

        return (bool)Configuration::updateValue(
            'FNE_REFUND_ORDER_STATE_ID',
            (int)$state->id
        );
    }
}