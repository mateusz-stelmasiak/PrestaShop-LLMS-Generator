<?php
/**
 * PrestaShop LLMS Generator
 *
 * Generates /llms.txt at the shop root: a Markdown index of the shop's CMS
 * pages, categories and products, in every active language, where each entry
 * carries its real meta description instead of a generic type label.
 *
 * Design notes (2.0.0)
 * --------------------
 * Single file on purpose. No namespace, no PSR-4 autoloader, no src/ tree.
 * Shared hosts run antivirus scanners that quarantine dynamic class loaders by
 * renaming them, which turns a `require_once` into a fatal error and takes the
 * back office down with it. A module that writes one text file does not need
 * that surface.
 *
 * Generation runs three flat SQL queries, one per entity type, covering every
 * language at once. URLs are built from `id + link_rewrite`, which lets
 * Link::getCMSLink() / getCategoryLink() / getProductLink() skip object
 * hydration entirely (verified against PrestaShop 8.2.4).
 *
 * Author : ADLX (https://github.com/iamadlx)
 * License: MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Llms_Generator extends Module
{
    /* Settings carried over from 1.x. Values are preserved on upgrade. */
    const CFG_TITLE = 'PSLLMS_TITLE';
    const CFG_DESC = 'PSLLMS_DESC';
    const CFG_INC_CMS = 'PSLLMS_INCLUDE_CMS';
    const CFG_INC_CATS = 'PSLLMS_INCLUDE_CATEGORIES';
    const CFG_INC_PRODUCTS = 'PSLLMS_INCLUDE_PRODUCTS';
    const CFG_EXCL_CMS_IDS = 'PSLLMS_EXCLUDED_CMS_IDS';

    /* New in 2.0.0. */
    const CFG_INC_TIMESTAMP = 'PSLLMS_INCLUDE_TIMESTAMP';
    const CFG_CRON_TOKEN = 'PSLLMS_CRON_TOKEN';
    const CFG_LAST_GENERATED = 'PSLLMS_LAST_GENERATED';
    const CFG_LAST_DURATION = 'PSLLMS_LAST_DURATION_MS';
    const CFG_LAST_BYTES = 'PSLLMS_LAST_BYTES';
    const CFG_LAST_COUNTS = 'PSLLMS_LAST_COUNTS';
    const CFG_LAST_ERROR = 'PSLLMS_LAST_ERROR';

    /** Fallback descriptions are clamped to this length on a word boundary. */
    const DESC_MAX_CHARS = 200;

    public function __construct()
    {
        $this->name = 'ps_llms_generator';
        $this->tab = 'seo';
        $this->version = '2.0.1';
        $this->author = 'ADLX';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'PrestaShop LLMS Generator';
        $this->description = 'Generates /llms.txt so AI assistants can discover your CMS pages, categories and products.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $title = [];
        $desc = [];
        foreach (Language::getLanguages(false) as $lang) {
            $title[(int) $lang['id_lang']] = '';
            $desc[(int) $lang['id_lang']] = '';
        }

        Configuration::updateValue(self::CFG_TITLE, $title, true);
        Configuration::updateValue(self::CFG_DESC, $desc, true);
        Configuration::updateValue(self::CFG_INC_CMS, 1);
        Configuration::updateValue(self::CFG_INC_CATS, 1);
        Configuration::updateValue(self::CFG_INC_PRODUCTS, 1);
        Configuration::updateValue(self::CFG_INC_TIMESTAMP, 1);
        Configuration::updateValue(self::CFG_EXCL_CMS_IDS, json_encode([]));
        $this->getCronToken();

        return true;
    }

    public function uninstall()
    {
        $keys = [
            self::CFG_TITLE, self::CFG_DESC, self::CFG_INC_CMS, self::CFG_INC_CATS,
            self::CFG_INC_PRODUCTS, self::CFG_INC_TIMESTAMP, self::CFG_EXCL_CMS_IDS,
            self::CFG_CRON_TOKEN, self::CFG_LAST_GENERATED, self::CFG_LAST_DURATION,
            self::CFG_LAST_BYTES, self::CFG_LAST_COUNTS, self::CFG_LAST_ERROR,
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        // Leave no orphan file behind, robots.txt may still point at it.
        $path = $this->getOutputPath();
        if (is_file($path)) {
            @unlink($path);
        }

        return parent::uninstall();
    }

    // -----------------------------------------------------------------
    // Generation
    // -----------------------------------------------------------------

    /**
     * Build /llms.txt and swap it into place atomically.
     *
     * @return array{ok:bool, bytes:int, duration_ms:int, counts:array, error:string|null, path:string}
     */
    public function generate()
    {
        $start = microtime(true);
        $path = $this->getOutputPath();
        $tmp = null;

        try {
            $counts = [];
            $content = $this->buildContent($counts);

            $tmp = $this->getTempPath();
            if (@file_put_contents($tmp, $content) === false) {
                throw new RuntimeException('Cannot write temporary file: ' . $tmp);
            }
            if (!@rename($tmp, $path)) {
                throw new RuntimeException('Cannot move temporary file to ' . $path);
            }
            $tmp = null;
            @chmod($path, 0644);

            $report = [
                'ok' => true,
                'bytes' => strlen($content),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'counts' => $counts,
                'error' => null,
                'path' => $path,
            ];
        } catch (Throwable $e) {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
            $report = [
                'ok' => false,
                'bytes' => 0,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'counts' => [],
                'error' => $e->getMessage(),
                'path' => $path,
            ];
        }

        Configuration::updateValue(self::CFG_LAST_GENERATED, gmdate('c'));
        Configuration::updateValue(self::CFG_LAST_DURATION, (int) $report['duration_ms']);
        Configuration::updateValue(self::CFG_LAST_BYTES, (int) $report['bytes']);
        Configuration::updateValue(self::CFG_LAST_COUNTS, json_encode($report['counts']));
        Configuration::updateValue(self::CFG_LAST_ERROR, (string) $report['error']);

        return $report;
    }

    /**
     * Assemble the whole document in memory.
     *
     * A string buffer is the right tool for a few hundred rows per language.
     * Streaming only pays off on shops with tens of thousands of SKUs and it
     * costs a lot of moving parts.
     *
     * @param array $counts filled with per-section entry counts
     *
     * @return string
     */
    protected function buildContent(array &$counts)
    {
        $context = Context::getContext();
        $idShop = (int) $context->shop->id;
        $idDefaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $languages = $this->getOrderedLanguages($idShop, $idDefaultLang);
        if (empty($languages)) {
            throw new RuntimeException('No active language found for this shop.');
        }

        $langIds = [];
        foreach ($languages as $lang) {
            $langIds[] = (int) $lang['id_lang'];
        }

        $incCms = (int) Configuration::get(self::CFG_INC_CMS) === 1;
        $incCats = (int) Configuration::get(self::CFG_INC_CATS) === 1;
        $incProducts = (int) Configuration::get(self::CFG_INC_PRODUCTS) === 1;

        $cmsRows = $incCms ? $this->fetchCms($idShop, $langIds) : [];
        $catRows = $incCats ? $this->fetchCategories($idShop, $langIds) : [];
        $productRows = $incProducts ? $this->fetchProducts($idShop, $langIds) : [];

        $out = [];

        $title = $this->cleanLine(Configuration::get(self::CFG_TITLE, $idDefaultLang));
        if ($title === '') {
            $title = $this->cleanLine($context->shop->name);
        }
        $summary = $this->cleanLine(Configuration::get(self::CFG_DESC, $idDefaultLang));
        if ($summary === '') {
            $summary = $title;
        }

        $out[] = '# ' . $title;
        $out[] = '> ' . $summary;
        $out[] = '';

        if ((int) Configuration::get(self::CFG_INC_TIMESTAMP) === 1) {
            $out[] = 'Generated: ' . gmdate('c');
            $out[] = '';
        }

        $out[] = 'Sections below are grouped by language and content type. '
            . 'Each entry links to the canonical page for that resource.';
        $out[] = '';

        foreach ($languages as $lang) {
            $idLang = (int) $lang['id_lang'];
            $iso = strtoupper((string) $lang['iso_code']);
            $langName = $this->cleanLangName(isset($lang['name']) ? $lang['name'] : $iso);
            $labels = $this->labels($iso);
            $heading = '## ' . $langName . ' (' . $iso . ') - ';

            $out[] = $heading . $labels['main'];
            $out[] = '- ' . $this->mdLink(
                $labels['home'],
                $context->link->getPageLink('index', true, $idLang, null, false, $idShop)
            ) . ': ' . $labels['home_desc'];
            $out[] = '';

            if ($incCms && !empty($cmsRows[$idLang])) {
                $out[] = $heading . $labels['cms_section'];
                foreach ($cmsRows[$idLang] as $row) {
                    $out[] = '- ' . $this->mdLink(
                        $row['title'],
                        $context->link->getCMSLink(
                            $row['id_cms'],
                            $row['link_rewrite'],
                            true,
                            $idLang,
                            $idShop
                        )
                    ) . ': ' . $this->describe($row['description'], $labels['cms']);
                }
                $counts['cms'][$iso] = count($cmsRows[$idLang]);
                $out[] = '';
            }

            if ($incCats && !empty($catRows[$idLang])) {
                $out[] = $heading . $labels['cat_section'];
                foreach ($catRows[$idLang] as $row) {
                    $out[] = '- ' . $this->mdLink(
                        $row['title'],
                        $context->link->getCategoryLink(
                            $row['id_category'],
                            $row['link_rewrite'],
                            $idLang,
                            null,
                            $idShop
                        )
                    ) . ': ' . $this->describe($row['description'], $labels['cat']);
                }
                $counts['categories'][$iso] = count($catRows[$idLang]);
                $out[] = '';
            }

            if ($incProducts && !empty($productRows[$idLang])) {
                $out[] = $heading . $labels['product_section'];
                foreach ($productRows[$idLang] as $row) {
                    $out[] = '- ' . $this->mdLink(
                        $row['title'],
                        $context->link->getProductLink(
                            $row['id_product'],
                            $row['link_rewrite'],
                            $row['category_rewrite'],
                            // A falsy ean13 makes Link hydrate the Product just to
                            // read it back. The default route has no ean13 keyword,
                            // so this placeholder never reaches the URL.
                            $row['ean13'] !== '' ? $row['ean13'] : '0',
                            $idLang,
                            $idShop
                        )
                    ) . ': ' . $this->describe($row['description'], $labels['product']);
                }
                $counts['products'][$iso] = count($productRows[$idLang]);
                $out[] = '';
            }
        }

        // Single trailing newline, and no BOM: the file is parsed by machines,
        // and a BOM shows up as a stray glyph in front of the H1 in renderers.
        return rtrim(implode("\n", $out), "\n") . "\n";
    }

    // -----------------------------------------------------------------
    // Data access: one flat query per entity type, all languages at once
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<int, array>> rows grouped by id_lang
     */
    protected function fetchCms($idShop, array $langIds)
    {
        $excluded = $this->getExcludedCmsIds();
        $where = empty($excluded)
            ? ''
            : ' AND c.id_cms NOT IN (' . implode(',', $excluded) . ')';

        $sql = 'SELECT cl.id_lang, c.id_cms, cl.meta_title, cl.meta_description, cl.link_rewrite
                FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs
                    ON (cs.id_cms = c.id_cms AND cs.id_shop = ' . (int) $idShop . ')
                INNER JOIN ' . _DB_PREFIX_ . 'cms_lang cl
                    ON (cl.id_cms = c.id_cms
                        AND cl.id_shop = ' . (int) $idShop . '
                        AND cl.id_lang IN (' . implode(',', $langIds) . '))
                WHERE c.active = 1' . $where . '
                ORDER BY cl.id_lang ASC, c.id_cms ASC';

        $grouped = [];
        foreach ($this->query($sql) as $row) {
            $title = $this->cleanLine($row['meta_title']);
            if ($title === '') {
                $title = 'CMS #' . (int) $row['id_cms'];
            }
            $grouped[(int) $row['id_lang']][] = [
                'id_cms' => (int) $row['id_cms'],
                'title' => $title,
                'link_rewrite' => (string) $row['link_rewrite'],
                'description' => $this->cleanLine($row['meta_description']),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array<int, array>> rows grouped by id_lang
     */
    protected function fetchCategories($idShop, array $langIds)
    {
        $skip = array_unique([
            (int) Configuration::get('PS_HOME_CATEGORY'),
            (int) Configuration::get('PS_ROOT_CATEGORY'),
            1,
        ]);

        $sql = 'SELECT cl.id_lang, c.id_category, cl.name, cl.meta_description,
                       cl.description, cl.link_rewrite
                FROM ' . _DB_PREFIX_ . 'category c
                INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                    ON (cs.id_category = c.id_category AND cs.id_shop = ' . (int) $idShop . ')
                INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON (cl.id_category = c.id_category
                        AND cl.id_shop = ' . (int) $idShop . '
                        AND cl.id_lang IN (' . implode(',', $langIds) . '))
                WHERE c.active = 1
                  AND c.id_category NOT IN (' . implode(',', $skip) . ')
                ORDER BY cl.id_lang ASC, c.id_category ASC';

        $grouped = [];
        foreach ($this->query($sql) as $row) {
            $description = $this->cleanLine($row['meta_description']);
            if ($description === '') {
                $description = $this->clamp($this->cleanLine($row['description']), self::DESC_MAX_CHARS);
            }
            $grouped[(int) $row['id_lang']][] = [
                'id_category' => (int) $row['id_category'],
                'title' => $this->cleanLine($row['name']),
                'link_rewrite' => (string) $row['link_rewrite'],
                'description' => $description,
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array<int, array>> rows grouped by id_lang
     */
    protected function fetchProducts($idShop, array $langIds)
    {
        $sql = 'SELECT pl.id_lang, p.id_product, p.ean13, pl.name, pl.meta_description,
                       pl.description_short, pl.link_rewrite,
                       cl.link_rewrite AS category_rewrite
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                    ON (ps.id_product = p.id_product AND ps.id_shop = ' . (int) $idShop . ')
                INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                    ON (pl.id_product = p.id_product
                        AND pl.id_shop = ' . (int) $idShop . '
                        AND pl.id_lang IN (' . implode(',', $langIds) . '))
                LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON (cl.id_category = p.id_category_default
                        AND cl.id_shop = ' . (int) $idShop . '
                        AND cl.id_lang = pl.id_lang)
                WHERE ps.active = 1
                  AND ps.visibility IN ("both", "catalog", "search")
                ORDER BY pl.id_lang ASC, p.id_product ASC';

        $grouped = [];
        foreach ($this->query($sql) as $row) {
            $description = $this->cleanLine($row['meta_description']);
            if ($description === '') {
                $description = $this->clamp($this->cleanLine($row['description_short']), self::DESC_MAX_CHARS);
            }
            $grouped[(int) $row['id_lang']][] = [
                'id_product' => (int) $row['id_product'],
                'title' => $this->cleanLine($row['name']),
                'link_rewrite' => (string) $row['link_rewrite'],
                'category_rewrite' => isset($row['category_rewrite']) ? (string) $row['category_rewrite'] : '',
                'ean13' => isset($row['ean13']) ? trim((string) $row['ean13']) : '',
                'description' => $description,
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array>
     */
    protected function query($sql)
    {
        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    // -----------------------------------------------------------------
    // Text helpers
    // -----------------------------------------------------------------

    /**
     * Turn shop content into a single safe Markdown line: strip HTML, decode
     * entities, drop emoji and control characters, normalise dashes, collapse
     * whitespace.
     */
    protected function cleanLine($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        // Block-level tags would otherwise glue two sentences together.
        $value = str_ireplace(
            ['<br>', '<br/>', '<br />', '</p>', '</li>', '</div>', '</h1>', '</h2>', '</h3>'],
            ' ',
            $value
        );
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = $this->stripEmoji($value);
        $value = $this->normaliseDashes($value);
        $value = $this->replaceUnicode('/[\x{0000}-\x{001F}\x{007F}\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', $value);
        $value = $this->replaceUnicode('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Remove pictographs, dingbats, flags and variation selectors.
     *
     * Deliberately keeps the copyright, registered and trademark signs, which
     * belong to legitimate brand names such as "QuietAir(TM) ResMed", and keeps
     * general punctuation such as the bullet character.
     */
    protected function stripEmoji($value)
    {
        $pattern = '/['
            . '\x{1F000}-\x{1FAFF}'  // pictographs, symbols and supplements
            . '\x{1F1E6}-\x{1F1FF}'  // regional indicators (flags)
            . '\x{2190}-\x{21FF}'    // arrows
            . '\x{2300}-\x{23FF}'    // technical symbols (watch, hourglass)
            . '\x{2460}-\x{24FF}'    // enclosed alphanumerics
            . '\x{25A0}-\x{27BF}'    // geometric shapes and dingbats
            . '\x{2B00}-\x{2BFF}'    // supplemental arrows and stars
            . '\x{FE00}-\x{FE0F}'    // variation selectors
            . '\x{200D}'             // zero width joiner
            . ']/u';

        return $this->replaceUnicode($pattern, '', $value);
    }

    /**
     * Em dash and en dash are banned from published content, so a stray one in
     * a meta description never reaches the file.
     */
    protected function normaliseDashes($value)
    {
        return $this->replaceUnicode('/[\x{2013}\x{2014}\x{2015}]/u', '-', $value);
    }

    /**
     * preg_replace returns null on malformed UTF-8. Falling back to the input
     * keeps a single bad byte from blanking an entire product name.
     */
    private function replaceUnicode($pattern, $replacement, $value)
    {
        $result = preg_replace($pattern, $replacement, $value);

        return $result === null ? $value : $result;
    }

    /**
     * Clamp on a word boundary, appending an ellipsis when the text is cut.
     */
    protected function clamp($value, $maxChars)
    {
        $value = (string) $value;
        if ($maxChars <= 0 || mb_strlen($value, 'UTF-8') <= $maxChars) {
            return $value;
        }

        $cut = mb_substr($value, 0, $maxChars, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > (int) ($maxChars * 0.5)) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($cut, " ,;:.!?-") . '...';
    }

    /**
     * Fall back to the generic type label only when no description exists.
     */
    protected function describe($description, $fallback)
    {
        $description = trim((string) $description);

        return $description !== '' ? $description : $fallback;
    }

    /**
     * Build `[label](url)`. Brackets and parentheses break the link grammar, so
     * they are replaced in the label and encoded in the URL.
     */
    protected function mdLink($label, $url)
    {
        $label = $this->cleanLine($label);
        $label = str_replace(['[', ']', '(', ')', '|'], ' ', $label);
        $label = trim($this->replaceUnicode('/\s+/u', ' ', $label));

        $url = trim((string) $url);
        $url = str_replace(
            [' ', "\t", "\n", "\r", '(', ')'],
            ['%20', '', '', '', '%28', '%29'],
            $url
        );

        if ($label === '') {
            $label = $url;
        }

        return '[' . $label . '](' . $url . ')';
    }

    private function cleanLangName($name)
    {
        // "Francais (French)" becomes "Francais".
        return trim($this->replaceUnicode('/\s*\(.*\)\s*$/u', '', (string) $name));
    }

    /**
     * Section labels per language, falling back to English.
     *
     * @return array<string, string>
     */
    protected function labels($iso)
    {
        $map = [
            'EN' => [
                'main' => 'Main', 'home' => 'Home', 'home_desc' => 'Shop home page',
                'cms_section' => 'CMS pages', 'cms' => 'CMS page',
                'cat_section' => 'Categories', 'cat' => 'Product category',
                'product_section' => 'Products', 'product' => 'Product',
            ],
            'FR' => [
                'main' => 'Principal', 'home' => 'Accueil', 'home_desc' => 'Page d\'accueil de la boutique',
                'cms_section' => 'Pages CMS', 'cms' => 'Page CMS',
                'cat_section' => 'Categories', 'cat' => 'Categorie de produits',
                'product_section' => 'Produits', 'product' => 'Produit',
            ],
            'NL' => [
                'main' => 'Hoofd', 'home' => 'Startpagina', 'home_desc' => 'Startpagina van de winkel',
                'cms_section' => 'CMS-paginas', 'cms' => 'CMS-pagina',
                'cat_section' => 'Categorieen', 'cat' => 'Productcategorie',
                'product_section' => 'Producten', 'product' => 'Product',
            ],
            'DE' => [
                'main' => 'Haupt', 'home' => 'Startseite', 'home_desc' => 'Startseite des Shops',
                'cms_section' => 'CMS-Seiten', 'cms' => 'CMS-Seite',
                'cat_section' => 'Kategorien', 'cat' => 'Produktkategorie',
                'product_section' => 'Produkte', 'product' => 'Produkt',
            ],
        ];

        $iso = strtoupper((string) $iso);

        return isset($map[$iso]) ? $map[$iso] : $map['EN'];
    }

    // -----------------------------------------------------------------
    // Paths, languages, token
    // -----------------------------------------------------------------

    public function getOutputPath()
    {
        return rtrim(_PS_ROOT_DIR_, '/') . '/llms.txt';
    }

    /**
     * Temporary file used for the atomic swap.
     *
     * It has to sit on the same filesystem as the destination for rename() to
     * be atomic, but outside the document root so a crashed run never leaves a
     * publicly readable leftover. The official guidance is to keep temporary
     * files under var/cache/<env>/modules/<module>/, which satisfies both.
     */
    protected function getTempPath()
    {
        $name = 'llms-' . bin2hex(random_bytes(6)) . '.tmp';

        foreach ($this->getTempDirCandidates() as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return rtrim($dir, '/') . '/' . $name;
            }
        }

        // Last resort: next to the destination, dot-prefixed so it is at least
        // not served by a directory listing.
        return rtrim(_PS_ROOT_DIR_, '/') . '/.' . $name;
    }

    /**
     * @return string[]
     */
    private function getTempDirCandidates()
    {
        $root = rtrim(_PS_ROOT_DIR_, '/');
        $candidates = [];

        if (defined('_PS_CACHE_DIR_')) {
            $candidates[] = rtrim(_PS_CACHE_DIR_, '/') . '/modules/' . $this->name;
        }
        $candidates[] = $root . '/var/cache/modules/' . $this->name;
        $candidates[] = $root . '/var';

        return $candidates;
    }

    /**
     * Active languages, shop default first, then alphabetically.
     *
     * @return array<int, array>
     */
    protected function getOrderedLanguages($idShop, $idDefaultLang)
    {
        $languages = Language::getLanguages(true, $idShop);
        if (!is_array($languages)) {
            return [];
        }

        usort($languages, function ($a, $b) use ($idDefaultLang) {
            $aId = isset($a['id_lang']) ? (int) $a['id_lang'] : 0;
            $bId = isset($b['id_lang']) ? (int) $b['id_lang'] : 0;
            if ($aId === $idDefaultLang && $bId !== $idDefaultLang) {
                return -1;
            }
            if ($bId === $idDefaultLang && $aId !== $idDefaultLang) {
                return 1;
            }

            return strcasecmp(
                isset($a['name']) ? (string) $a['name'] : '',
                isset($b['name']) ? (string) $b['name'] : ''
            );
        });

        return $languages;
    }

    /**
     * @return int[]
     */
    protected function getExcludedCmsIds()
    {
        $decoded = json_decode((string) Configuration::get(self::CFG_EXCL_CMS_IDS), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $decoded)));
    }

    /**
     * @return int[]
     */
    protected function getAllCmsIds()
    {
        $sql = 'SELECT c.id_cms FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs
                    ON (cs.id_cms = c.id_cms AND cs.id_shop = ' . (int) $this->context->shop->id . ')
                ORDER BY c.id_cms ASC';

        $ids = [];
        foreach ($this->query($sql) as $row) {
            $ids[] = (int) $row['id_cms'];
        }

        return $ids;
    }

    /**
     * Secret used by the cron endpoint, created on demand so an upgrade from
     * 1.x never lands without one.
     */
    public function getCronToken()
    {
        $token = (string) Configuration::get(self::CFG_CRON_TOKEN);
        if (strlen($token) < 32) {
            $token = bin2hex(random_bytes(24));
            Configuration::updateValue(self::CFG_CRON_TOKEN, $token);
        }

        return $token;
    }

    public function rotateCronToken()
    {
        $token = bin2hex(random_bytes(24));
        Configuration::updateValue(self::CFG_CRON_TOKEN, $token);

        return $token;
    }

    // -----------------------------------------------------------------
    // Back office
    // -----------------------------------------------------------------

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPsLlmsGenerator')) {
            $output .= $this->postProcess();
        }

        if (Tools::isSubmit('submitPsLlmsGenerateNow')) {
            $output .= $this->renderReport($this->generate());
        }

        if (Tools::isSubmit('submitPsLlmsRotateToken')) {
            $this->rotateCronToken();
            $output .= $this->displayConfirmation('Cron token rotated. Update your cron command.');
        }

        return $output
            . $this->renderStyles()
            . $this->renderStatusPanel()
            . $this->renderCharsetNotice()
            . $this->renderForm()
            . $this->renderCronPanel()
            . $this->renderScript();
    }

    protected function postProcess()
    {
        $title = [];
        $desc = [];
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $title[$idLang] = (string) Tools::getValue(self::CFG_TITLE . '_' . $idLang, '');
            $desc[$idLang] = (string) Tools::getValue(self::CFG_DESC . '_' . $idLang, '');
        }
        Configuration::updateValue(self::CFG_TITLE, $title, true);
        Configuration::updateValue(self::CFG_DESC, $desc, true);

        $incCms = (int) Tools::getValue(self::CFG_INC_CMS, 0);
        Configuration::updateValue(self::CFG_INC_CMS, $incCms);
        Configuration::updateValue(self::CFG_INC_CATS, (int) Tools::getValue(self::CFG_INC_CATS, 0));
        Configuration::updateValue(self::CFG_INC_PRODUCTS, (int) Tools::getValue(self::CFG_INC_PRODUCTS, 0));
        Configuration::updateValue(self::CFG_INC_TIMESTAMP, (int) Tools::getValue(self::CFG_INC_TIMESTAMP, 0));

        // Exclusions are stored rather than inclusions, so CMS pages created
        // later are picked up automatically.
        $excluded = [];
        if ($incCms) {
            $selected = Tools::getValue('PSLLMS_CMS_ENABLED', []);
            if (!is_array($selected)) {
                $selected = [];
            }
            $selected = array_map('intval', $selected);
            foreach ($this->getAllCmsIds() as $id) {
                if (!in_array($id, $selected, true)) {
                    $excluded[] = $id;
                }
            }
        }
        Configuration::updateValue(self::CFG_EXCL_CMS_IDS, json_encode(array_values(array_unique($excluded))));

        return $this->displayConfirmation('Settings saved.')
            . $this->renderReport($this->generate());
    }

    protected function renderReport(array $report)
    {
        if (empty($report['ok'])) {
            return $this->displayError(
                'Generation failed: ' . htmlspecialchars((string) $report['error'], ENT_QUOTES, 'UTF-8')
            );
        }

        $summary = [];
        foreach ($report['counts'] as $type => $perLang) {
            $summary[] = $type . ' ' . array_sum($perLang);
        }

        $url = htmlspecialchars($this->getPublicUrl(), ENT_QUOTES, 'UTF-8');

        return $this->displayConfirmation(sprintf(
            'llms.txt generated in %d ms, %s%s.',
            (int) $report['duration_ms'],
            $this->formatBytes((int) $report['bytes']),
            empty($summary) ? '' : ', ' . implode(', ', $summary)
        )) . '<p><a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="'
            . $url . '">Open llms.txt</a></p>';
    }

    protected function renderStatusPanel()
    {
        $lastGenerated = (string) Configuration::get(self::CFG_LAST_GENERATED);
        $lastError = (string) Configuration::get(self::CFG_LAST_ERROR);
        $url = htmlspecialchars($this->getPublicUrl(), ENT_QUOTES, 'UTF-8');

        $rows = [];
        $rows[] = ['File', is_file($this->getOutputPath())
            ? '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>'
            : '<span class="text-danger">not generated yet</span>'];
        $rows[] = ['Last generated', $lastGenerated !== ''
            ? htmlspecialchars($lastGenerated, ENT_QUOTES, 'UTF-8')
            : '<span class="text-muted">never</span>'];
        $rows[] = ['Last duration', ((int) Configuration::get(self::CFG_LAST_DURATION)) . ' ms'];
        $rows[] = ['Size', $this->formatBytes((int) Configuration::get(self::CFG_LAST_BYTES))];

        $counts = json_decode((string) Configuration::get(self::CFG_LAST_COUNTS), true);
        if (is_array($counts) && !empty($counts)) {
            $parts = [];
            foreach ($counts as $type => $perLang) {
                if (!is_array($perLang)) {
                    continue;
                }
                $parts[] = htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8')
                    . ': ' . (int) array_sum($perLang)
                    . ' (' . htmlspecialchars(implode(', ', array_keys($perLang)), ENT_QUOTES, 'UTF-8') . ')';
            }
            if (!empty($parts)) {
                $rows[] = ['Entries', implode('<br>', $parts)];
            }
        }

        if ($lastError !== '') {
            $rows[] = ['Last error', '<span class="text-danger">'
                . htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') . '</span>'];
        }

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-dashboard"></i> Status</div>'
            . '<table class="table psllms-status">';
        foreach ($rows as $row) {
            $html .= '<tr><th>' . $row[0] . '</th><td>' . $row[1] . '</td></tr>';
        }
        $html .= '</table>'
            . '<form method="post" action="' . htmlspecialchars($this->getCurrentIndex(), ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" name="submitPsLlmsGenerateNow" class="btn btn-primary">'
            . '<i class="icon-refresh"></i> Generate now</button>'
            . '</form></div>';

        return $html;
    }

    /**
     * Apache serves .txt as `text/plain` with no charset. Browsers then fall back to
     * Latin-1 and every accent renders as mojibake, which looks like a broken file
     * even though the bytes are valid UTF-8.
     *
     * The notice is only shown when the generated file actually contains non-ASCII
     * bytes, so English-only shops never see it.
     */
    protected function renderCharsetNotice()
    {
        if (!$this->outputHasNonAscii()) {
            return '';
        }

        return '<div class="alert alert-info">'
            . '<p><strong>Accented characters showing up as <code>Ã©</code> in your browser?</strong></p>'
            . '<p>Your file is written in UTF-8, but Apache serves <code>.txt</code> without a '
            . 'charset, so the browser guesses Latin-1. Add this line to your <code>.htaccess</code>, '
            . 'after the <code># ~~end~~</code> marker so PrestaShop does not overwrite it:</p>'
            . '<pre>AddCharset UTF-8 .txt</pre>'
            . '<p>Nothing to change in the module, and no need for a UTF-8 BOM: a BOM renders as a '
            . 'stray glyph in front of the heading in most Markdown readers.</p>'
            . '</div>';
    }

    /**
     * Cheap probe on the head of the generated file. Only the first chunk is read,
     * which is enough to catch any shop whose content is not plain ASCII.
     */
    protected function outputHasNonAscii()
    {
        $path = $this->getOutputPath();
        if (!is_file($path)) {
            return false;
        }

        $head = @file_get_contents($path, false, null, 0, 65536);
        if ($head === false || $head === '') {
            return false;
        }

        return preg_match('/[\x80-\xFF]/', $head) === 1;
    }

    protected function renderCronPanel()
    {
        $url = htmlspecialchars($this->getPublicUrl('module/ps_llms_generator/regenerate'), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($this->getCronToken(), ENT_QUOTES, 'UTF-8');

        return '<div class="panel">'
            . '<div class="panel-heading"><i class="icon-time"></i> Scheduled regeneration (optional)</div>'
            . '<p class="help-block">Generation is manual by default. To automate it, call the endpoint below from '
            . 'a cron job. Prefer the header form, the token then stays out of your access logs.</p>'
            . '<pre>curl -fsS -H "X-Llms-Token: ' . $token . '" "' . $url . '"</pre>'
            . '<p class="help-block">A query string fallback (<code>?token=...</code>) is accepted for cron runners '
            . 'that cannot send headers, but it will show up in your server logs.</p>'
            . '<form method="post" action="' . htmlspecialchars($this->getCurrentIndex(), ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" name="submitPsLlmsRotateToken" class="btn btn-default btn-sm">Rotate token</button>'
            . '</form></div>';
    }

    protected function renderForm()
    {
        $idDefaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $excluded = $this->getExcludedCmsIds();

        $cmsHtml = '<div id="psllms_cms_box">'
            . '<p class="help-block">Uncheck the CMS pages you want to keep out of llms.txt. '
            . 'Pages created later are included automatically.</p>'
            . '<div class="psllms-actions">'
            . '<button type="button" class="btn btn-default btn-sm" id="psllms_check_all">Check all</button> '
            . '<button type="button" class="btn btn-default btn-sm" id="psllms_uncheck_all">Uncheck all</button>'
            . '</div>';

        $cmsList = $this->getCmsPickerList((int) $this->context->language->id);
        if (empty($cmsList)) {
            $cmsHtml .= '<div class="alert alert-info">No CMS page found.</div>';
        } else {
            $cmsHtml .= '<div class="psllms-scroll">';
            foreach ($cmsList as $row) {
                $id = (int) $row['id_cms'];
                $checked = in_array($id, $excluded, true) ? '' : ' checked="checked"';
                $label = $this->cleanLine($row['meta_title']);
                if ($label === '') {
                    $label = 'CMS #' . $id;
                }
                $cmsHtml .= '<div class="checkbox"><label>'
                    . '<input type="checkbox" name="PSLLMS_CMS_ENABLED[]" value="' . $id . '"' . $checked . '> '
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                    . '</label></div>';
            }
            $cmsHtml .= '</div>';
        }
        $cmsHtml .= '</div>';

        $fields = [
            'form' => [
                'legend' => ['title' => 'llms.txt settings', 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => 'Site title',
                        'name' => self::CFG_TITLE,
                        'lang' => true,
                        'desc' => 'Heading of the file. Falls back to the shop name.',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => 'Global description',
                        'name' => self::CFG_DESC,
                        'lang' => true,
                        'rows' => 6,
                        'autoload_rte' => false,
                        'desc' => 'One paragraph describing the shop, used as the blockquote summary.',
                    ],
                    $this->switchInput(self::CFG_INC_CMS, 'Include CMS pages'),
                    ['type' => 'html', 'name' => 'cms_picker', 'html_content' => $cmsHtml],
                    $this->switchInput(self::CFG_INC_CATS, 'Include categories'),
                    $this->switchInput(
                        self::CFG_INC_PRODUCTS,
                        'Include products',
                        'Every active, visible product is listed. Prices and stock are deliberately left out: '
                        . 'a file regenerated by hand would advertise stale ones.'
                    ),
                    $this->switchInput(
                        self::CFG_INC_TIMESTAMP,
                        'Include generation timestamp',
                        'Writes a Generated: line so you can tell at a glance when the file was last refreshed.'
                    ),
                ],
                'submit' => ['title' => 'Save and generate', 'class' => 'btn btn-primary'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $idDefaultLang;
        $helper->id_language = $idDefaultLang;
        $helper->languages = $this->context->controller->getLanguages();
        $helper->allow_employee_form_lang = 0;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitPsLlmsGenerator';

        $helper->fields_value[self::CFG_INC_CMS] = (int) Configuration::get(self::CFG_INC_CMS);
        $helper->fields_value[self::CFG_INC_CATS] = (int) Configuration::get(self::CFG_INC_CATS);
        $helper->fields_value[self::CFG_INC_PRODUCTS] = (int) Configuration::get(self::CFG_INC_PRODUCTS);
        $helper->fields_value[self::CFG_INC_TIMESTAMP] = (int) Configuration::get(self::CFG_INC_TIMESTAMP);

        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $helper->fields_value[self::CFG_TITLE][$idLang] = (string) Configuration::get(self::CFG_TITLE, $idLang);
            $helper->fields_value[self::CFG_DESC][$idLang] = (string) Configuration::get(self::CFG_DESC, $idLang);
        }

        return $helper->generateForm([$fields]);
    }

    /**
     * @return array<string, mixed>
     */
    private function switchInput($name, $label, $desc = null)
    {
        $input = [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => 'Yes'],
                ['id' => $name . '_off', 'value' => 0, 'label' => 'No'],
            ],
        ];
        if ($desc !== null) {
            $input['desc'] = $desc;
        }

        return $input;
    }

    /**
     * @return array<int, array>
     */
    protected function getCmsPickerList($idLang)
    {
        $idShop = (int) $this->context->shop->id;
        $sql = 'SELECT c.id_cms, cl.meta_title
                FROM ' . _DB_PREFIX_ . 'cms c
                INNER JOIN ' . _DB_PREFIX_ . 'cms_shop cs
                    ON (cs.id_cms = c.id_cms AND cs.id_shop = ' . $idShop . ')
                INNER JOIN ' . _DB_PREFIX_ . 'cms_lang cl
                    ON (cl.id_cms = c.id_cms AND cl.id_shop = ' . $idShop . '
                        AND cl.id_lang = ' . (int) $idLang . ')
                ORDER BY cl.meta_title ASC';

        return $this->query($sql);
    }

    protected function getCurrentIndex()
    {
        return AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    protected function getPublicUrl($suffix = 'llms.txt')
    {
        $base = $this->context->link->getBaseLink((int) $this->context->shop->id, true);

        return rtrim($base, '/') . '/' . ltrim($suffix, '/');
    }

    protected function formatBytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    protected function renderStyles()
    {
        return '<style>'
            . '.psllms-scroll{max-height:360px;overflow:auto;border:1px solid #ddd;padding:10px;'
            . 'border-radius:4px;background:#fff}'
            . '.psllms-actions{margin:8px 0 12px}'
            . '.psllms-status th{width:180px}'
            . '</style>';
    }

    /**
     * Nowdoc keeps the JavaScript verbatim.
     *
     * The 1.x build interpolated PHP into a double-quoted string and emitted
     * `querySelectorAll("input[name="PSLLMS_CMS_ENABLED[]"]")`, an unparseable
     * selector, which is why the check-all buttons silently did nothing.
     */
    protected function renderScript()
    {
        return <<<'JS'
<script>
(function () {
    function boxes() {
        return document.querySelectorAll('input[name="PSLLMS_CMS_ENABLED[]"]');
    }
    function setAll(checked) {
        Array.prototype.forEach.call(boxes(), function (box) { box.checked = checked; });
    }
    function toggleBox() {
        var picked = document.querySelector('input[name="PSLLMS_INCLUDE_CMS"]:checked');
        var box = document.getElementById('psllms_cms_box');
        if (box) {
            box.style.display = (picked && picked.value === '1') ? '' : 'none';
        }
    }
    function bind() {
        var all = document.getElementById('psllms_check_all');
        var none = document.getElementById('psllms_uncheck_all');
        if (all) { all.addEventListener('click', function () { setAll(true); }); }
        if (none) { none.addEventListener('click', function () { setAll(false); }); }
        document.addEventListener('change', function (event) {
            if (event.target && event.target.name === 'PSLLMS_INCLUDE_CMS') { toggleBox(); }
        });
        toggleBox();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
</script>
JS;
    }
}
