<?php
/**
 * Migration 2.0.0 to 2.0.1.
 *
 * No configuration change: 2.0.1 only adds a back-office notice about the
 * missing charset on `.txt` responses, which is computed at render time.
 * The script exists so PrestaShop has an explicit, logged step for the
 * version bump rather than an implicit one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ps_Llms_Generator $module
 *
 * @return bool
 */
function upgrade_module_2_0_1($module)
{
    // Seed the timestamp toggle for shops that upgraded straight from 1.x
    // before opening the configuration page, where it would have defaulted
    // to "No" simply because the key did not exist yet.
    if (Configuration::get(Ps_Llms_Generator::CFG_INC_TIMESTAMP) === false) {
        Configuration::updateValue(Ps_Llms_Generator::CFG_INC_TIMESTAMP, 1);
    }

    $module->getCronToken();

    return true;
}
