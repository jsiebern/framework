<?php

if ( ! function_exists('dd'))
{
    /**
     * Dies and dumps.
     *
     * @return string
     */
    function dd()
    {
        call_user_func_array('dump', func_get_args());

        die;
    }
}

if ( ! function_exists('content_directory'))
{
    /**
     * Gets the content directory.
     *
     * @return string
     */
    function content_directory()
    {
        return WP_CONTENT_DIR;
    }
}

if ( ! function_exists('plugin_directory'))
{
    /**
     * Gets the plugin directory.
     *
     * @return string
     */
    function plugin_directory()
    {
        return WP_PLUGIN_DIR;
    }
}

if ( ! function_exists('response'))
{
    /**
     * Generates a response.
     *
     * @param  string  $body
     * @param  integer $status
     * @param  array   $headers
     * @return \Herbert\Framework\Response
     */
    function response($body, $status = 200, $headers = null)
    {
        return new Herbert\Framework\Response($body, $status, $headers);
    }
}

if ( ! function_exists('json_response'))
{
    /**
     * Generates a json response.
     *
     * @param  mixed   $jsonable
     * @param  integer $status
     * @param  array   $headers
     * @return \Herbert\Framework\Response
     */
    function json_response($jsonable, $status = 200, $headers = null)
    {
        return new Herbert\Framework\JsonResponse($jsonable, $status, $headers);
    }
}

if ( ! function_exists('redirect_response'))
{
    /**
     * Generates a redirect response.
     *
     * @param  string  $url
     * @param  integer $status
     * @param  array   $headers
     * @return \Herbert\Framework\Response
     */
    function redirect_response($url, $status = 302, $headers = null)
    {
        return new Herbert\Framework\RedirectResponse($url, $status, $headers);
    }
}

if ( ! function_exists('herbert'))
{
    /**
     * Gets the herbert container.
     *
     * @param  string $binding
     * @return string
     */
    function herbert($binding = null)
    {
        $instance = Herbert\Framework\Application::getInstance();

        if ( ! $binding)
        {
            return $instance;
        }

        return $instance[$binding];
    }
}

if ( ! function_exists('errors'))
{
    /**
     * Get the errors.
     *
     * @param string key
     * @return array
     */
    function errors($key = null)
    {
        $errors = herbert('errors');
        $errors = isset($errors[0]) ? $errors[0] : $errors;

        if (!$key)
        {
            return $errors;
        }

        return array_get($errors, $key);
    }
}

if ( ! function_exists('session'))
{
    /**
     * Gets the session or a key from the session.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return \Illuminate\Session\Store|mixed
     */
    function session($key = null, $default = null)
    {
        if ($key === null)
        {
            return herbert('session');
        }

        return herbert('session')->get($key, $default);
    }
}

if ( ! function_exists('session_flashed'))
{
    /**
     * Gets the session flashbag or a key from the session flashbag.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return \Illuminate\Session\Store|mixed
     */
    function session_flashed($key = null, $default = [])
    {
        if ($key === null)
        {
            return herbert('session')->getFlashBag();
        }

        return herbert('session')->getFlashBag()->get($key, $default);
    }
}

if ( ! function_exists('view'))
{
    /**
     * Renders a twig view.
     *
     * @param  string $name
     * @param  array  $context
     * @return string
     */
    function view($name, $context = [])
    {
        return response(herbert('Twig_Environment')->render($name, $context));
    }
}

if ( ! function_exists('panel_url'))
{
    /**
     * Gets the url to a panel.
     *
     * @param  string $name
     * @param  array  $query
     * @return string
     */
    function panel_url($name, $query = [])
    {
        return add_query_arg($query, herbert('panel')->url($name));
    }
}

if ( ! function_exists('route_url'))
{
    /**
     * Gets the url to a route.
     *
     * @param  string $name
     * @param  array  $args
     * @param  array  $query
     * @return string
     */
    function route_url($name, $args = [], $query = [])
    {
        return add_query_arg($query, herbert('router')->url($name, $args));
    }
}

if ( ! function_exists('needs_upgrade'))
{
    /**
     * Checks if a plugin needs an upgrade
     * @param string $plugin_path
     * @return boolean
     */
    function needs_upgrade($plugin_path)
    {
        try {
            $plugin_data = get_plugin_data($plugin_path.'/plugin.php');

            $plugin_versions = unserialize(get_option('herbert_plugin_versions', serialize([])));
            if (!isset($plugin_versions[$plugin_data['Name']]) || version_compare($plugin_versions[$plugin_data['Name']], $plugin_data['Version'] , '<')) {
                $plugin_versions[$plugin_data['Name']] = $plugin_data['Version'];
                if (get_option('herbert_plugin_versions')) {
                    update_option('herbert_plugin_versions', serialize($plugin_versions));
                }
                else {
                    add_option('herbert_plugin_versions', serialize($plugin_versions));
                }
                
                return true;
            }

            return false;
        }
        catch (Exception $e) {
            return false;
        }
    }
}

if ( ! function_exists('herbert_encrypt') ) {
    function herbert_encrypt($value, $key) {
        $salt = openssl_random_pseudo_bytes(8);
        $salted = '';
        $dx = '';
        while (strlen($salted) < 48) {
            $dx = md5($dx.$key.$salt, true);
            $salted .= $dx;
        }
        $key = substr($salted, 0, 32);
        $iv  = substr($salted, 32,16);
        $encrypted_data = openssl_encrypt($value, 'aes-256-cbc', $key, true, $iv);
        $data = array('ct' => base64_encode($encrypted_data), 'iv' => bin2hex($iv), 's' => bin2hex($salt));
        return serialize($data);
    }
}

if ( ! function_exists('herbert_decrypt') ) {
    function herbert_decrypt($value, $key) {
        $data = unserialize($value);
        try {
            $salt = hex2bin($data['s']);
            $iv  = hex2bin($data['iv']);
        } catch(Exception $e) { return null; }
        $ct = base64_decode($data['ct']);
        $concatedPassphrase = $key.$salt;
        $md5 = array();
        $md5[0] = md5($concatedPassphrase, true);
        $result = $md5[0];
        for ($i = 1; $i < 3; $i++) {
            $md5[$i] = md5($md5[$i - 1].$concatedPassphrase, true);
            $result .= $md5[$i];
        }
        $key = substr($result, 0, 32);
        $data = openssl_decrypt($ct, 'aes-256-cbc', $key, true, $iv);
        return $data;
    }
}