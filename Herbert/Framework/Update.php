<?php
namespace Herbert\Framework;

define('HERBERT_CRYPT_KEY', 'd-kP"3MNGj6%D3T]R\'^2');

class Update {
    private $update_url;
    private $buylink;
    private $root;
    private $plugin_slug;
    private $plugin_data;
    private $app;
    private $license = null;

    public function __construct(Application $app, $update, $root) {
        $this->app = $app;
        $this->update_url = array_get($update, 'url', null);
        $this->buylink = array_get($update, 'buylink', null);
        $this->root = $root;
        $this->plugin_slug = plugin_basename($root);
        $this->plugin_data = get_plugin_data($root.'/plugin.php');

        $this->getLicense();

        if ($this->update_url) {
            $this->registerPluginRowMeta();
            $this->registerPluginInfo();
            $this->registerPluginUpdate();
        }

        $this->registerRoutes();
    }

    private function registerRoutes() {
        $this->app->router->get([
            'as'    => $this->plugin_slug.'_free_license',
            'uri'   => '/wp-admin/'.$this->plugin_slug.'/free-license',
            'uses'  => [ $this, 'freeLicense' ]
        ]);

        $this->app->router->post([
            'as'    => $this->plugin_slug.'_licensing',
            'uri'   => '/wp-admin/'.$this->plugin_slug.'/save-license',
            'uses'  => [ $this, 'saveLicense' ]
        ]);
    }

    public function isLicensed() {
        return file_exists($this->root.'/app/cache.php');
    }

    public function licenseIsValid() {
        if ($this->license) {
            if (time() >= $this->license->warning_ends_at) {
                return 'warning_ended';
            }
            if (time() >= $this->license->grace_ends_at) {
                return 'grace_ended';
            }
            if (time() >= $this->license->revalidate_at) {
                return 'needs_revalidation';
            }

            return true;
        }
        else {
            return 'unavailable';
        }
    }

    private function refetchLicense() {

    }

    public function printRevalidationFailedScreen() {
        if (is_admin()) {
            $buy = ($this->buylink) ? '<br><br>Um '.$this->plugin_data['Name'].' zu aktivieren klicken Sie <a href="'.$this->buylink.'" target="_blank">hier</a>.' : '';
            Notifier::warning('
                <b>Achtung - Die Lizenz für '.$this->plugin_data['Name'].' konnte seit dem '.date('d.m.Y', $this->license->revalidate_at).' nicht erneuert werden.</b>
                <br><br>
                '.$this->plugin_data['Name'].' wird zum '.date('d.m.Y', $this->license->grace_ends_at).' automatisch abgeschaltet.
                Sollten Sie einen Fehler vermuten, kontaktieren Sie bitte den Support.'.$buy
            );
        }
    }

    public function printGraceEndedScreen() {
        if (is_admin()) {
            $uri = '/wp-admin/'.$this->plugin_slug.'/free-license';
            $buy = ($this->buylink) ? '<br><br>Um '.$this->plugin_data['Name'].' zu aktivieren klicken Sie <a href="'.$this->buylink.'" target="_blank">hier</a>.' : '';
            Notifier::error('
                <b>Achtung - Die Lizenz für '.$this->plugin_data['Name'].' konnte seit dem '.date('d.m.Y', $this->license->revalidate_at).' nicht erneuert werden.</b>
                <br><br>
                '.$this->plugin_data['Name'].' wurde automatisch abgeschaltet.
                Sollten Sie einen Fehler vermuten, kontaktieren Sie bitte den Support.'.$buy.'
                <br>
                Um Ihre Lizenz auf diesem Blog zurückzusetzen, klicken Sie <a href="'.$uri.'">hier</a>.'
            );
        }
    }

    public function printLicenseEnterScreen() {
        if (is_admin()) {
            $uri = '/wp-admin/'.$this->plugin_slug.'/save-license';
            Notifier::error('
                <b>Achtung - '.$this->plugin_data['Name'].' ist nicht lizensiert.</b><br><br>
                Bitte geben Sie Ihren Lizenzschlüssel ein:
                <form action="'.$uri.'" method="POST">
                    <input type="text" name="license-key" value="e2eb44f35342fb362304a9a16352b23dxx">
                    <button type="submit">Aktivieren</button>
                </form>'
            );
        }
    }

    public function freeLicense(Http $http) {
        if ($this->license) {
            $key = $this->license->key;

            try {
                $response = wp_remote_post(
                    $this->update_url.'/'.$this->plugin_slug.'/license/free', [
                        'headers' => [
                            'license-key' => $key
                        ],
                        'body' => [
                            'domain' => str_replace([ 'http://', 'https://' ], '', site_url())
                        ]
                    ]
                );

                if ($response['response']['code'] != 200) {
                    throw new \Exception('Unerwartete Lizenzserverantwort: <i>'.$response['response']['message'].'</i>.<br>Bitte kontaktieren Sie den Support.');
                }

                $body = json_decode(array_get($response, 'body', ''), true);
                if ($body && isset($body['status']) && isset($body['payload'])) {
                    if ($body['status'] == 'success') {
                        
                    }
                    else {
                        throw new \Exception($body['payload'].'.');
                    }
                }
                else {
                    throw new \Exception('Unerwartete Serverantwort: <i>Fehlerhaftes Format</i>.<br>Bitte kontaktieren Sie den Support.');
                }
            }
            catch(\Exception $e) {
                Notifier::error('
                    <b>Achtung - Fehler bei der Lizenzfreigabe für '.$this->plugin_data['Name'].':</b>
                    <br><br>
                    '.$e->getMessage().'<br><br><i>Ihre Lizenz wurde dennoch zurückgesetzt.</i>'
                , true);
            }
        }
        
        
        $this->removeLicense();

        // dd($http->isJSON());
        $redirect = (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : admin_url();
        wp_redirect($redirect);
        exit;
    }

    public function saveLicense(Http $http) {
        $key = $http->input('license-key');

        try {
            $license = $this->fetchLicenseFromServer($key);
            $this->storeLicense($license);

            Notifier::success('
                <b>'.$this->plugin_data['Name'].' wurde erfolgreich lizensiert.</b>
            ', true);
        }
        catch (\Exception $e) {
            Notifier::error('
                <b>Achtung - Fehler bei der Lizenzeingabe für '.$this->plugin_data['Name'].':</b>
                <br><br>
                '.$e->getMessage()
            , true);
        }

        $redirect = (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : admin_url();
        wp_redirect($redirect);
        exit;
    }

    private function getLicense() {
        $license = get_option($this->getOptionName());
        if ($license) {
            $this->license = unserialize($license);

            if ($this->license->revalidate_at <= time()) {
                $this->refetchLicense();
            }
        }
    }

    private function removeLicense() {
        \delete_option($this->getOptionName());
        $this->license = null;
    }

    private function storeLicense(Cache $license) {
        if (\get_option($this->getOptionName())) {
            \update_option($this->getOptionName(), serialize($license));
        }
        else {
            \add_option($this->getOptionName(), serialize($license));
        }
        
        $this->license = $license;
    }

    private function getOptionName() {
        return $this->plugin_slug.'_system_cache';
    }

    public function fetchLicenseFromServer($key) {
        if ($key && strlen($key) == 34) {
            $response = wp_remote_post(
                $this->update_url.'/'.$this->plugin_slug.'/license/fetch', [
                    'headers' => [
                        'license-key' => $key
                    ],
                    'body' => [
                        'domain' => str_replace([ 'http://', 'https://' ], '', site_url()),
                        'wordpress_version' => get_bloginfo('version'),
                        'php_version' => phpversion(),
                        'language' => get_bloginfo('language'),
                        'locale' => get_locale(),
                        'plugin_version' => $this->plugin_data['Version']
                    ]
                ]
            );

            if ($response['response']['code'] != 200) {
                throw new \Exception('Unerwartete Lizenzserverantwort: <i>'.$response['response']['message'].'</i>.<br>Bitte kontaktieren Sie den Support.');
            }

            $body = json_decode(array_get($response, 'body', ''), true);
            if ($body && isset($body['status']) && isset($body['payload'])) {
                if ($body['status'] == 'success') {
                    return new Cache($body['payload']);
                }
                else {
                    throw new \Exception('Fehler bei der Linzensierung: <i>'.$body['payload'].'</i>.');
                }
            }
            else {
                throw new \Exception('Unerwartete Lizenzserverantwort: <i>Fehlerhaftes Format</i>.<br>Bitte kontaktieren Sie den Support.');
            }
        }
        else {
            throw new \Exception('"'.$key.'" ist kein gültiger Lizenzschlüssel');
        }
    }

    private function registerPluginRowMeta() {
        $plugin = $this->plugin_slug;
        $plugin_data = $this->plugin_data;

        add_filter('plugin_row_meta', function($links, $file) use($plugin, $plugin_data) {
            if (strpos($file, $plugin) !== false) {
                $links[2] = sprintf( '<a href="%s" class="thickbox" aria-label="%s" data-title="%s">%s</a>',
                    esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $file .
                        '&TB_iframe=true&width=600&height=550' ) ),
                    esc_attr( sprintf( __( 'More information about %s' ), $plugin_data['Name'] ) ),
                    esc_attr( $plugin_data['Name'] ),
                    __( 'View details' )
                );
            }

            return $links;
        }, 10, 2);
    }

    private function registerPluginInfo() {
        $url = $this->update_url;
        $plugin = $this->plugin_slug;
        $plugin_data = $this->plugin_data;

        add_filter('plugins_api', function($def, $action, $params) use($url, $plugin, $plugin_data) {
            $is_for_me = strpos($params->slug, $plugin) !== false;
            if (!$is_for_me) {
                return $def;
            }

            if ($action != 'plugin_information') {
                return $def;
            }

            $params->plugin_name = $plugin_data['Name'];
            $params->name = $plugin_data['Name'];
            $params->author = $plugin_data['AuthorName'];
            $params->homepage = $plugin_data['PluginURI'];

            try {
                $response = wp_remote_get(
                    $url.'/'.$plugin.'/info'
                );

                if ($response['response']['code'] != 200) {
                    throw new \Exception('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>'.$response['response']['message'].'.</i>');
                }

                $body = json_decode(array_get($response, 'body', ''), true);
                if ($body && isset($body['status']) && isset($body['payload'])) {
                    if ($body['status'] == 'success') {
                        foreach ($body['payload'] as $key=>$val) {
                            $params->$key = $val;
                        }
                        $params->sections = [
                            'description' => $plugin_data['Description'],
                            'changelog' => $body['payload']['changelog']
                        ];
                    }
                    else {
                        Notifier::warning('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>'.$body['payload'].'.</i>');
                    }
                }
                else {
                    Notifier::warning('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>Fehler bei der Verbindung zum Updateserver: Fehlerhaftes Format..</i>');
                }
            }
            catch (\Exception $e) {
                $params = new WP_Error('plugins_api_failed', $e->getMessage());
            }

            return $params;
        }, 10, 3);
    }

    private function registerPluginUpdate() {
        $url = $this->update_url;
        $plugin = $this->plugin_slug;
        $plugin_data = $this->plugin_data;
        $license = $this->license;

        add_filter('pre_set_site_transient_update_plugins', function($transient) use($url, $plugin, $plugin_data, $license) {
            if (empty($transient) || !is_object($transient)) {
                return $transient;
            }
            if (empty($transient->checked)) {
                return $transient;
            }

            $plugin_file = $plugin.'/plugin.php';

            try {
                $response = wp_remote_post(
                    $url.'/'.$plugin.'/version', [
                        'headers' => [
                            'license-key' => ($license) ? $license->key : null
                        ],
                        'body' => [
                            'domain' => str_replace([ 'http://', 'https://' ], '', site_url()),
                            'plugin_version' => $plugin_data['Version']
                        ]
                    ]
                );
                if ($response['response']['code'] != 200) {
                    throw new \Exception($response['response']['message']);
                }
                $body = json_decode(array_get($response, 'body', ''), true);
                if ($body && isset($body['status']) && isset($body['payload'])) {
                    if ($body['status'] == 'success') {
                        if (isset($body['payload']['new_version']) && $body['payload']['new_version']) {
                            $obj = new \stdClass();
                            $obj->slug = $plugin;
                            $obj->new_version = $body['payload']['new_version'];
                            $obj->url = $plugin_data['PluginURI'];
                            $obj->package =  $body['payload']['url'];
                            $transient->response[$plugin_file] = $obj;
                        }
                    }
                    else {
                        Notifier::warning('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>'.$body['payload'].'.</i>');
                    }
                }
                else {
                    Notifier::warning('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>Fehler bei der Verbindung zum Updateserver: Fehlerhaftes Format..</i>');
                }
            }
            catch (Exception $e) {
                Notifier::warning('<b>'.$plugin_data['Name'].' Updatewarnung:</b> <i>Fehler bei der Verbindung zum Updateserver:: '.$e->getMessage().'.</i>');
            }

            return $transient;
        });
    }
}

class Cache implements \Serializable {
    public $key;
    public $domain;
    public $email;
    public $last_validated;
    public $revalidate_at;
    public $grace_ends_at;
    public $warning_ends_at;
    public $meta;

    public function __construct($license_data) {
        if (is_array($license_data)) {
            foreach ($license_data as $key => $val) {
                $this->$key = $val;
            }
        }
    }

    public function serialize() {
        $collect = [];
        foreach (get_class_vars('Herbert\Framework\Cache') as $key => $val) {
            $collect[$key] = serialize($this->$key);
        }
        return herbert_encrypt(serialize($collect), HERBERT_CRYPT_KEY);
    }

    public function unserialize($serialized) {
        $collect = unserialize(herbert_decrypt($serialized, HERBERT_CRYPT_KEY));
        if (is_array($collect)) {
            foreach ($collect as $key => $val) {
                $this->$key = unserialize($val);
            }
        }
    }
}