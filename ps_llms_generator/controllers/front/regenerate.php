<?php
/**
 * Regeneration endpoint, so /llms.txt can be refreshed from outside the back
 * office: a host cron job, the official `cronjobs` module, or any scheduler
 * able to make an HTTP request.
 *
 *   curl -fsS -H "X-Llms-Token: <secret>" https://example.com/module/ps_llms_generator/regenerate
 *
 * The header form is preferred. A `?token=` query string is accepted for cron
 * runners that cannot send headers, but it ends up in the access logs.
 *
 * Nothing here runs unless the module is installed and the token matches, and
 * the endpoint is never advertised: generation stays manual until the merchant
 * wires a schedule.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Llms_GeneratorRegenerateModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        // Never render the theme around a JSON payload.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');

        if (!$this->isAuthorised()) {
            http_response_code(403);
            $this->respond(['ok' => false, 'error' => 'Invalid token']);
        }

        $module = Module::getInstanceByName('ps_llms_generator');
        if (!($module instanceof Ps_Llms_Generator)) {
            http_response_code(500);
            $this->respond(['ok' => false, 'error' => 'Module not available']);
        }

        // A full catalogue on a shared host can outlive the default limits.
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');

        $report = $module->generate();
        if (empty($report['ok'])) {
            http_response_code(500);
        }

        $this->respond([
            'ok' => (bool) $report['ok'],
            'bytes' => (int) $report['bytes'],
            'duration_ms' => (int) $report['duration_ms'],
            'counts' => $report['counts'],
            'error' => $report['error'],
        ]);
    }

    /**
     * Constant-time comparison against the stored secret.
     */
    private function isAuthorised()
    {
        $expected = (string) Configuration::get(Ps_Llms_Generator::CFG_CRON_TOKEN);
        if (strlen($expected) < 32) {
            // No token provisioned means the endpoint stays shut rather than open.
            return false;
        }

        $provided = '';
        if (isset($_SERVER['HTTP_X_LLMS_TOKEN'])) {
            $provided = (string) $_SERVER['HTTP_X_LLMS_TOKEN'];
        }
        if ($provided === '') {
            $provided = (string) Tools::getValue('token', '');
        }
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * @param array $payload
     */
    private function respond(array $payload)
    {
        echo json_encode($payload);
        exit;
    }
}
