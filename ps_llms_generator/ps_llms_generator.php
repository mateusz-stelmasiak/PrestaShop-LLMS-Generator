<?php
if (!defined('_PS_VERSION_')) { exit; }

class Ps_Llms_Generator extends Module
{
    const CFG_TITLE = 'PSLLMS_TITLE';
    const CFG_DESC = 'PSLLMS_DESC';
    const CFG_INC_CMS = 'PSLLMS_INCLUDE_CMS';
    const CFG_INC_CATS = 'PSLLMS_INCLUDE_CATEGORIES';
    const CFG_INC_PRODUCTS = 'PSLLMS_INCLUDE_PRODUCTS';
    const CFG_EXCL_CMS_IDS = 'PSLLMS_EXCLUDED_CMS_IDS'; // JSON array

    public function __construct()
    {
        $this->name = 'ps_llms_generator';
        $this->tab = 'seo';
        $this->version = '1.0.5';
        $this->author = 'ADLX';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'PrestaShop LLMS Generator';
        $this->description = 'Generates /llms.txt for your store (CMS, categories, products).';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
    }

    /**
     * Tiny i18n helper: backend language follows the SHOP default language (FR/EN).
     * This avoids relying on PS translation keys that can be fragile for a minimal OSS module.
     */
    private function t($fr, $en)
    {
        $idDefault = (int) Configuration::get('PS_LANG_DEFAULT');
        $iso = (string) Language::getIsoById($idDefault);
        return (strtolower($iso) === 'fr') ? $fr : $en;
    }

    public function install()
    {
        $langs = Language::getLanguages(false);
        $title = [];
        $desc = [];
        foreach ($langs as $lang) {
            $title[(int)$lang['id_lang']] = '';
            $desc[(int)$lang['id_lang']] = '';
        }

        return parent::install()
            && Configuration::updateValue(self::CFG_TITLE, $title, true)
            && Configuration::updateValue(self::CFG_DESC, $desc, true)
            && Configuration::updateValue(self::CFG_INC_CMS, 1)
            && Configuration::updateValue(self::CFG_INC_CATS, 1)
            && Configuration::updateValue(self::CFG_INC_PRODUCTS, 1)
            && Configuration::updateValue(self::CFG_EXCL_CMS_IDS, json_encode([]));
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName(self::CFG_TITLE)
            && Configuration::deleteByName(self::CFG_DESC)
            && Configuration::deleteByName(self::CFG_INC_CMS)
            && Configuration::deleteByName(self::CFG_INC_CATS)
            && Configuration::deleteByName(self::CFG_INC_PRODUCTS)
            && Configuration::deleteByName(self::CFG_EXCL_CMS_IDS);
    }

    public function getContent()
    {
        $output = '';

        
        // Minimal UI tweaks (keep backend clean)
        $output .= '<style>
            .psllms-panel{margin-top:0}
            .psllms-scroll{max-height:360px;overflow:auto;border:1px solid #ddd;padding:10px;border-radius:6px;background:#fff}
            .psllms-textarea{min-height:140px}
        </style>';
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", function(){
                function toggleCmsBox(){
                    var on = document.querySelector("input[name=\"PSLLMS_INCLUDE_CMS\"]:checked");
                    var val = on ? parseInt(on.value,10) : 0;
                    var box = document.getElementById("psllms_cms_box");
                    if(box){ box.style.display = val === 1 ? "block" : "none"; }
                }
                var radios = document.querySelectorAll("input[name=\"PSLLMS_INCLUDE_CMS\"]");
                radios.forEach(function(r){ r.addEventListener("change", toggleCmsBox); });
                toggleCmsBox();
            
                // Check all / uncheck all CMS checkboxes
                var btnAll = document.getElementById("psllms_cms_check_all");
                var btnNone = document.getElementById("psllms_cms_uncheck_all");
                function setAllCmsCheckboxes(checked){
                    var box = document.getElementById("psllms_cms_box");
                    if(!box) return;
                    box.querySelectorAll("input[name="PSLLMS_CMS_ENABLED[]"]").forEach(function(cb){
                        cb.checked = checked;
                    });
                }
                if(btnAll){ btnAll.addEventListener("click", function(){ setAllCmsCheckboxes(true); }); }
                if(btnNone){ btnNone.addEventListener("click", function(){ setAllCmsCheckboxes(false); }); }
});
        </script>';
if (Tools::isSubmit('submitPsLlmsGenerator')) {
            $output .= $this->postProcess();
        }

        $output .= $this->renderForm();
        $output .= $this->renderToggleScript();

        return $output;
    }

    private function postProcess()
    {
        $langs = Language::getLanguages(false);

        // Multilang fields
        $title = [];
        $desc = [];
        foreach ($langs as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $title[$id_lang] = (string)Tools::getValue(self::CFG_TITLE . '_' . $id_lang, '');
            $desc[$id_lang] = (string)Tools::getValue(self::CFG_DESC . '_' . $id_lang, '');
        }

        Configuration::updateValue(self::CFG_TITLE, $title, true);
        Configuration::updateValue(self::CFG_DESC, $desc, true);

        // Toggles
        $incCms = (int)Tools::getValue(self::CFG_INC_CMS, 0);
        $incCats = (int)Tools::getValue(self::CFG_INC_CATS, 0);
        $incProducts = (int)Tools::getValue(self::CFG_INC_PRODUCTS, 0);

        Configuration::updateValue(self::CFG_INC_CMS, $incCms);
        Configuration::updateValue(self::CFG_INC_CATS, $incCats);
        Configuration::updateValue(self::CFG_INC_PRODUCTS, $incProducts);

        // CMS exclusions: store excluded IDs so new CMS are included by default
        $excluded = [];
        if ($incCms) {
            $selected = Tools::getValue('PSLLMS_CMS_ENABLED', []);
            if (!is_array($selected)) {
                $selected = [];
            }
            $selected = array_map('intval', $selected);

            $allCmsIds = $this->getAllCmsIds();
            foreach ($allCmsIds as $cmsId) {
                if (!in_array((int)$cmsId, $selected, true)) {
                    $excluded[] = (int)$cmsId;
                }
            }
        }
        Configuration::updateValue(self::CFG_EXCL_CMS_IDS, json_encode(array_values(array_unique($excluded))));

        // Regenerate on save
        try {
            $result = $this->generateAndWriteLlms();
            if ($result['ok']) {
                $llmsUrl = $this->getLlmsUrl();
                return $this->displayConfirmation($this->t(
                    'Paramètres enregistrés et llms.txt généré avec succès.',
                    'Settings saved and llms.txt generated successfully.'
                )) . '<p style="margin-top:10px;"><a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="' . $llmsUrl . '">'
                . $this->t('Ouvrir llms.txt', 'Open llms.txt')
                . '</a> <code style="margin-left:8px;">' . $llmsUrl . '</code></p>'; 
}
            return $this->displayError($this->t(
                'Enregistré, mais impossible d’écrire llms.txt : ',
                'Saved, but could not write llms.txt: '
            ) . $result['error']);
        } catch (Throwable $e) {
            return $this->displayError($this->t(
                'Erreur fatale lors de la génération de llms.txt : ',
                'Fatal error while generating llms.txt: '
            ) . $e->getMessage());
        }
    }

    private function renderForm()
    {
        $idDefaultShopLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $langs = Language::getLanguages(false);

        $excludedCms = $this->getExcludedCmsIds();
        $cmsList = $this->getCmsListForCurrentLang(); // list based on current BO language for readability

        // CMS checkbox HTML
        $cmsHtml = '<div id="psllms_cms_box" class="panel psllms-panel">';
        $cmsHtml .= '<p class="help-block">' . $this->t(
            'Décochez les pages CMS que vous souhaitez exclure du fichier llms.txt.',
            'Uncheck CMS pages you want to exclude from llms.txt.'
        ) . '</p>';
        $cmsHtml .= '<div class="psllms-actions" style="margin:8px 0 12px 0;">'
            . '<button type="button" class="btn btn-default btn-sm" id="psllms_cms_check_all">'
            . $this->t('Tout cocher', 'Check all')
            . '</button> '
            . '<button type="button" class="btn btn-default btn-sm" id="psllms_cms_uncheck_all">'
            . $this->t('Tout décocher', 'Uncheck all')
            . '</button>'
            . '</div>';


        if (empty($cmsList)) {
            $cmsHtml .= '<div class="alert alert-info">' . $this->t(
                'Aucune page CMS trouvée.',
                'No CMS pages found.'
            ) . '</div>';
        } else {
            $cmsHtml .= '<div class="psllms-scroll">';
            foreach ($cmsList as $row) {
                $id = (int)$row['id_cms'];
                $checked = in_array($id, $excludedCms, true) ? '' : 'checked="checked"';
                $label = htmlspecialchars($row['meta_title'] ?: ('CMS #' . $id), ENT_QUOTES, 'UTF-8');
                $cmsHtml .= '<div class="checkbox"><label>';
                $cmsHtml .= '<input type="checkbox" name="PSLLMS_CMS_ENABLED[]" value="' . $id . '" ' . $checked . '> ';
                $cmsHtml .= $label;
                $cmsHtml .= '</label></div>';
            }
            $cmsHtml .= '</div>';
        }
        $cmsHtml .= '</div>';

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->t('Paramètres LLMS.txt', 'LLMS.txt settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->t('Titre du site', 'Site title'),
                        'name' => self::CFG_TITLE,
                        'lang' => true,
                        'required' => false, 
],
                    [
                        'type' => 'textarea',
                        'label' => $this->t('Description globale', 'Global description'),
                        'name' => self::CFG_DESC,
                        'lang' => true,
                        'class' => 'psllms-textarea',
                        'autoload_rte' => false,
                        'required' => false,
                        'rows' => 8, 
'desc' => $this->t(
                            'Texte d’introduction utilisé en haut du fichier llms.txt.',
                            'Intro text used at the top of llms.txt.'
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->t('Inclure les pages CMS', 'Include CMS pages'),
                        'name' => self::CFG_INC_CMS,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'cms_on', 'value' => 1, 'label' => $this->t('Oui', 'Yes')],
                            ['id' => 'cms_off', 'value' => 0, 'label' => $this->t('Non', 'No')],
                        ],
                    ],
                    [
                        'type' => 'html',
                        'name' => 'cms_picker',
                        'html_content' => $cmsHtml,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->t('Inclure les catégories', 'Include categories'),
                        'name' => self::CFG_INC_CATS,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'cats_on', 'value' => 1, 'label' => $this->t('Oui', 'Yes')],
                            ['id' => 'cats_off', 'value' => 0, 'label' => $this->t('Non', 'No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->t('Inclure les produits', 'Include products'),
                        'name' => self::CFG_INC_PRODUCTS,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'prod_on', 'value' => 1, 'label' => $this->t('Oui', 'Yes')],
                            ['id' => 'prod_off', 'value' => 0, 'label' => $this->t('Non', 'No')],
                        ],
                        'desc' => $this->t('Tous les produits actifs seront inclus (sans limite).', 'All active products will be included (no limit).'),
                    ],
                ],
                'submit' => [
                    'title' => $this->t('Enregistrer', 'Save'),
                    'class' => 'btn btn-primary',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Force default shop language for the multi-lang tabs
        $helper->default_form_language = $idDefaultShopLang;
        $helper->id_language = $idDefaultShopLang;

        // Important: provide language list to show lang inputs properly
        $helper->languages = $this->context->controller->getLanguages();
        $helper->allow_employee_form_lang = 0;

        $helper->title = $this->displayName;
        $helper->submit_action = 'submitPsLlmsGenerator';

        // Fill values
        $helper->fields_value[self::CFG_INC_CMS] = (int)Configuration::get(self::CFG_INC_CMS);
        $helper->fields_value[self::CFG_INC_CATS] = (int)Configuration::get(self::CFG_INC_CATS);
        $helper->fields_value[self::CFG_INC_PRODUCTS] = (int)Configuration::get(self::CFG_INC_PRODUCTS);

        foreach ($langs as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $helper->fields_value[self::CFG_TITLE][$id_lang] = (string)Configuration::get(self::CFG_TITLE, $id_lang);
            $helper->fields_value[self::CFG_DESC][$id_lang] = (string)Configuration::get(self::CFG_DESC, $id_lang);
        }

        return $helper->generateForm([$fieldsForm]);
    }

    private function renderToggleScript()
    {
        // Minimal JS to show/hide CMS list when the toggle changes
        return '<script>
            (function(){
                function getCmsToggle(){
                    var el = document.querySelector("input[name=\'' . self::CFG_INC_CMS . '\']:checked");
                    return el ? el.value : "1";
                }
                function refresh(){
                    var box = document.getElementById("psllms_cms_box");
                    if(!box) return;
                    box.style.display = (getCmsToggle() === "1") ? "" : "none";
                }
                document.addEventListener("change", function(e){
                    if(e.target && e.target.name === "' . self::CFG_INC_CMS . '"){ refresh(); }
                });
                document.addEventListener("DOMContentLoaded", refresh);
                refresh();
            })();
        </script>';
    }

    private function getExcludedCmsIds()
    {
        $raw = (string)Configuration::get(self::CFG_EXCL_CMS_IDS);
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $arr)));
    }

    private function getAllCmsIds()
    {
        $sql = 'SELECT c.id_cms
                FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs ON (cs.id_cms = c.id_cms)
                WHERE cs.id_shop = ' . (int)$this->context->shop->id . '
                ORDER BY c.id_cms ASC';
        $rows = Db::getInstance()->executeS($sql);
        return array_map(static function ($r) { return (int)$r['id_cms']; }, $rows ?: []);
    }

    private function getCmsListForCurrentLang()
    {
        $id_lang = (int)$this->context->language->id;

        $sql = 'SELECT c.id_cms, cl.meta_title
                FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs ON (cs.id_cms = c.id_cms)
                INNER JOIN ' . _DB_PREFIX_ . 'cms_lang cl ON (cl.id_cms = c.id_cms AND cl.id_lang = ' . (int)$id_lang . ' AND cl.id_shop = ' . (int)$this->context->shop->id . ')
                WHERE cs.id_shop = ' . (int)$this->context->shop->id . '
                ORDER BY cl.meta_title ASC';
        return Db::getInstance()->executeS($sql) ?: [];
    }

    private function generateAndWriteLlms()
    {
        $content = $this->buildLlmsContent();

        // Force UTF-8 detection in most clients/browsers (avoids mojibake like "â€”", "ðŸ…")
        // Prepend a UTF-8 BOM once.
        $bom = "\xEF\xBB\xBF";
        if (strpos($content, $bom) !== 0) {
            $content = $bom . $content;
        }

        $path = _PS_ROOT_DIR_ . '/llms.txt';
        $ok = @file_put_contents($path, $content);
        if ($ok === false) {
            return ['ok' => false, 'error' => 'Cannot write to ' . $path . ' (permissions).'];
        }
        return ['ok' => true];
    }

    private function buildLlmsContent()
    {
        $incCms = (int)Configuration::get(self::CFG_INC_CMS);
        $incCats = (int)Configuration::get(self::CFG_INC_CATS);
        $incProducts = (int)Configuration::get(self::CFG_INC_PRODUCTS);

        $excludedCms = $this->getExcludedCmsIds();

        // The FILE must be in ALL shop languages (as requested)
        $languages = Language::getLanguages(true, $this->context->shop->id);

        $out = [];
        foreach ($languages as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $iso = (string)$lang['iso_code'];
            $langName = (string)$lang['name'];

            $title = (string)Configuration::get(self::CFG_TITLE, $id_lang);
            $desc = (string)Configuration::get(self::CFG_DESC, $id_lang);

            $out[] = '## ' . $langName . ' (' . strtoupper($iso) . ')';
            $out[] = '';
            $out[] = '# ' . $this->sanitizeLine($title ?: $this->context->shop->name);
            if (!empty($desc)) {
                $out[] = $this->sanitizeParagraph($desc);
            }
            $out[] = '';
            $out[] = '- ' . $this->mdLink('Home', $this->context->link->getPageLink('index', true, $id_lang));
            $out[] = '';

            if ($incCms) {
                $out[] = '### CMS';
                foreach ($this->getCmsIdsActive() as $cmsId) {
                    if (in_array((int)$cmsId, $excludedCms, true)) {
                        continue;
                    }
                    $cms = new CMS((int)$cmsId, $id_lang, $this->context->shop->id);
                    if (!Validate::isLoadedObject($cms)) {
                        continue;
                    }
                    $out[] = '- ' . $this->mdLink($this->sanitizeLine($cms->meta_title ?: ('CMS #' . $cmsId)), $this->context->link->getCMSLink($cms, null, true, $id_lang));
                }
                $out[] = '';
            }

            if ($incCats) {
                $out[] = '### Categories';
                foreach ($this->getAllCategoryIds() as $catId) {
                    $cat = new Category((int)$catId, $id_lang, $this->context->shop->id);
                    if (!Validate::isLoadedObject($cat) || !$cat->active) {
                        continue;
                    }
                    if ((int)$cat->id === (int)Configuration::get('PS_HOME_CATEGORY') || (int)$cat->id === 1) {
                        continue;
                    }
                    $out[] = '- ' . $this->mdLink($this->sanitizeLine($cat->name), $this->context->link->getCategoryLink($cat, null, $id_lang));
                }
                $out[] = '';
            }

            if ($incProducts) {
                $out[] = '### Products';
                foreach ($this->getAllActiveProductIds() as $pid) {
                    $p = new Product((int)$pid, false, $id_lang, $this->context->shop->id);
                    if (!Validate::isLoadedObject($p) || !$p->active) {
                        continue;
                    }
                    $out[] = '- ' . $this->mdLink($this->sanitizeLine($p->name), $this->context->link->getProductLink($p, null, null, null, $id_lang));
                }
                $out[] = '';
            }

            $out[] = '---';
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private function getCmsIdsActive()
    {
        $sql = 'SELECT c.id_cms
                FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs ON (cs.id_cms = c.id_cms)
                WHERE cs.id_shop = ' . (int)$this->context->shop->id . '
                AND c.active = 1
                ORDER BY c.id_cms ASC';
        $rows = Db::getInstance()->executeS($sql);
        return array_map(static function ($r) { return (int)$r['id_cms']; }, $rows ?: []);
    }

    private function getAllCategoryIds()
    {
        $sql = 'SELECT c.id_category
                FROM ' . _DB_PREFIX_ . 'category c
                INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs ON (cs.id_category = c.id_category)
                WHERE cs.id_shop = ' . (int)$this->context->shop->id . '
                AND c.active = 1
                ORDER BY c.id_category ASC';
        $rows = Db::getInstance()->executeS($sql);
        return array_map(static function ($r) { return (int)$r['id_category']; }, $rows ?: []);
    }

    private function getAllActiveProductIds()
    {
        $sql = 'SELECT p.id_product
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . (int)$this->context->shop->id . ')
                WHERE ps.active = 1
                ORDER BY p.id_product ASC';
        $rows = Db::getInstance()->executeS($sql);
        return array_map(static function ($r) { return (int)$r['id_product']; }, $rows ?: []);
    }

    
    private function getLlmsUrl()
    {
        $base = $this->context->link->getBaseLink($this->context->shop->id, true);
        return rtrim($base, '/') . '/llms.txt';
    }

private function mdLink($label, $url)
    {
        $label = trim((string)$label);
        $url = trim((string)$url);
        return '[' . str_replace(['[',']'], '', $label) . '](' . $url . ')';
    }

    private function sanitizeLine($s)
    {
        $s = strip_tags((string)$s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private function sanitizeParagraph($s)
    {
        $s = strip_tags((string)$s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
