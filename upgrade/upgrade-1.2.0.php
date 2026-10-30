
<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration vers SendToFNE CI 1.2.0.
 *
 * Ajoute l'onglet « Commandes FNE » à une installation existante.
 * Ne réinstalle pas le module et ne modifie pas les données commerciales.
 *
 * @param Module $module Instance du module en cours de mise à jour.
 *
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    require_once dirname(__FILE__). '/../classes/FneInstaller.php';

    $installer = new FneInstaller($module);

    return $installer->ensureTabsInstalled();
}
