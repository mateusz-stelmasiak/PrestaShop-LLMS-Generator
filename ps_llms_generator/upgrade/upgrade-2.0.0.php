<?php
/**
 * Migration 1.x to 2.0.0.
 *
 * The settings namespace is unchanged, so every value the merchant already set
 * (title, description, include toggles, excluded CMS list) is preserved as is.
 * All this does is seed the keys 2.0.0 adds, and provision a cron token so the
 * optional endpoint is not left open with an empty secret.
 *
 * No hook is registered: generation is manual by default, and hooks that fire
 * on every product save are what made the previous rewrite unpleasant to use.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ps_Llms_Generator $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    $defaults = [
        Ps_Llms_Generator::CFG_INC_TIMESTAMP => 1,
    ];

    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    // Status fields start empty, the first generation fills them.
    $blanks = [
        Ps_Llms_Generator::CFG_LAST_GENERATED,
        Ps_Llms_Generator::CFG_LAST_DURATION,
        Ps_Llms_Generator::CFG_LAST_BYTES,
        Ps_Llms_Generator::CFG_LAST_COUNTS,
        Ps_Llms_Generator::CFG_LAST_ERROR,
    ];
    foreach ($blanks as $key) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, '');
        }
    }

    $module->getCronToken();

    return true;
}
