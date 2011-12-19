<?php

/**
 * Optional CAS Authentication
 *
 * This plugin augments the RoundCube login page with the ability to authenticate
 * to a CAS server, which enables logging into RoundCube with identities
 * authenticated by the CAS server and acts as a CAS proxy to relay authenticated
 * credentials to the IMAP backend.
 * 
 * The vast majority of this plugin was written by Alex Li. David Warden modified
 * it to be an optional authentication method that can work with stock Roundcube
 * version 0.7.
 *
 * @version 0.7.0
 * @author David Warden (dfwarden@gmail.com)
 * @author Alex Li (li@hcs.harvard.edu)
 * 
 */

class cas_authn_opt extends rcube_plugin {
    
    private $cas_inited;
    
    /**
     * Initialize plugin
     *
     */
    function init() {
        // initialize plugin fields
        $this->cas_inited = false;
        
        // load plugin configuration
        $this->load_config();
        
        // add application hooks
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('authenticate', array($this, 'authenticate'));
        $this->add_hook('imap_connect', array($this, 'imap_connect'));
        $this->add_hook('template_object_loginform', array($this, 'add_cas_login_html'));
    }

    /**
     * Handle plugin-specific actions
     * These actions are handled at the startup hook rather than registered as
     * custom actions because the user session does not necessarily exist when
     * these actions need to be handled.
     *
     * @param array $args arguments from rcmail
     * @return array modified arguments
     */
    function startup($args) {
        // intercept PGT callback action from CAS server
        if ($args['action'] == 'pgtcallback') {
            // initialize CAS client
            $this->cas_init();
            
            // retrieve and store PGT if present
            phpCAS::forceAuthentication();
            
            // end script - once the PGT is stored we don't need to do anything else.
            exit;
        }
        
        // intercept logout action
        // We unfortunately cannot use the logout_after plugin hook because it is
        // executed after session is destroyed
        else if ($args['task'] == 'logout') {
            // initialize CAS client
            $this->cas_init();

            // Redirect to CAS logout action if user is logged in to CAS.
            // Also, do the normal Roundcube logout actions.
            if(phpCAS::isSessionAuthenticated()) {
                $RCMAIL = rcmail::get_instance();
                $RCMAIL->logout_actions();
                $RCMAIL->kill_session();
                $RCMAIL->plugins->exec_hook('logout_after', $userdata);
                phpCAS::logout();
                exit;
            }

        }

        // intercept CAS login
        else if ($args['action'] == 'caslogin') {
            // initialize CAS client
            $this->cas_init();
            
            // Force the user to log in to CAS, using a redirect if necessary.
            phpCAS::forceAuthentication();

            // If control reaches this point, user is authenticated to CAS.
            $user = phpCAS::getUser();
            $pass = '';
            // retrieve credentials, either a Proxy Ticket or 'masteruser' password
            $cfg = rcmail::get_instance()->config->all();
            if ($cfg['opt_cas_proxy']) {
                $_SESSION['cas_pt'][php_uname('n')] = phpCAS::retrievePT($cfg['opt_cas_imap_name'], $err_code, $output);
                $pass = $_SESSION['cas_pt'][php_uname('n')];
            }
            else {
                $pass = $cfg['opt_cas_imap_password'];
            }
    
            // Do Roundcube login actions
            $RCMAIL = rcmail::get_instance();
            $RCMAIL->login($user, $pass, $RCMAIL->autoselect_host());
            $RCMAIL->session->remove('temp');
            $RCMAIL->session->regenerate_id(false);
            $RCMAIL->session->set_auth_cookie();
     
            // log successful login
            rcmail_log_login();
         
            // allow plugins to control the redirect url after login success
            $redir = $RCMAIL->plugins->exec_hook('login_after', array('_task' => 'mail'));
            unset($redir['abort'], $redir['_err']);
         
            // send redirect, otherwise control will reach the mail display and fail because the 
            // IMAP session was already started by $RCMAIL->login()
            global $OUTPUT;
            $OUTPUT->redirect($redir);
        }

        return $args;
    }
    
    /**
     * Inject IMAP authentication credentials
     * If you are using this plugin in proxy mode, this will set the password 
     * to be used in RCMAIL->imap_connect to a Proxy Ticket for cas_imap_name.
     * If you are not using this plugin in proxy mode, it will do nothing. 
     * If you are using normal authentication, it will do nothing. 
     *
     * @param array $args arguments from rcmail
     * @return array modified arguments
     */
    function imap_connect($args) {
        // retrieve configuration
        $cfg = rcmail::get_instance()->config->all();
        
        // RoundCube is acting as CAS proxy
        if ($cfg['opt_cas_proxy']) {
            // a proxy ticket has been retrieved, the IMAP server caches proxy tickets, and this is the first connection attempt
            if ($_SESSION['cas_pt'][php_uname('n')] && $cfg['opt_cas_imap_caching'] && $args['attempt'] == 1) {
                // use existing proxy ticket in session
                $args['pass'] = $_SESSION['cas_pt'][php_uname('n')];
            }

            // no proxy tickets have been retrieved, the IMAP server doesn't cache proxy tickets, or the first connection attempt has failed
            else {
                // initialize CAS client
                $this->cas_init();

                // if CAS session exists, use that.
                // retrieve a new proxy ticket and store it in session
                if (phpCAS::isSessionAuthenticated()) {
                    $_SESSION['cas_pt'][php_uname('n')] = phpCAS::retrievePT($cfg['opt_cas_imap_name'], $err_code, $output);
                    $args['pass'] = $_SESSION['cas_pt'][php_uname('n')];
                }
            }
            
            // enable retry on the first connection attempt only
            if ($args['attempt'] <= 1) {
                $args['retry'] = true;
            }
        }
        
        return $args;
    }
 
    /**
    * Prepend link to CAS login above the Roundcube login form if the user would like to
    * login with CAS.
    */
    function add_cas_login_html($args) {
        $RCMAIL = rcmail::get_instance();
        $this->add_texts('localization');
    
        $loginbutton = new html_inputfield(array(
                                'type' => 'button',
                                'class' => 'button mainaction',
                                'value' => $this->gettext('casoptloginbutton')
                            ));    
        $caslogin_content = html::div(array(
                                'style' => 'border-bottom: 1px dotted #000; text-align: center; padding-bottom: 1em; margin-bottom: 1em;'),
                                html::a(array(
                                    'style' => 'text-decoration: none;',
                                    'href' => $this->generate_url(array('action' => 'caslogin')),
                                    'title' => $this->gettext('casoptloginbutton')),
                                    $loginbutton->show()
                                )
                            );
        $args['content'] = $caslogin_content . $args['content'];

        return $args;
    }

    /**
     * Initialize CAS client
     * 
     */
    private function cas_init() {
        if (!$this->cas_inited) {
            // retrieve configuration
            $cfg = rcmail::get_instance()->config->all();

            // include phpCAS
            require_once('CAS.php');
            
            // Uncomment the following line for phpCAS call tracing, helpful for debugging.
            //phpCAS::setDebug('/tmp/cas_debug.log');

            // initialize CAS client
            if ($cfg['opt_cas_proxy']) {
                phpCAS::proxy(CAS_VERSION_2_0, $cfg['opt_cas_hostname'], $cfg['opt_cas_port'], $cfg['opt_cas_uri'], false);

                // set URL for PGT callback
                phpCAS::setFixedCallbackURL($this->generate_url(array('action' => 'pgtcallback')));
                
                // set PGT storage
                phpCAS::setPGTStorageFile('xml', $cfg['opt_cas_pgt_dir']);
            }
            else {
                phpCAS::client(CAS_VERSION_2_0, $cfg['opt_cas_hostname'], $cfg['opt_cas_port'], $cfg['opt_cas_uri'], false);
            }

            // set service URL for authorization with CAS server
            phpCAS::setFixedServiceURL($this->generate_url(array('action' => 'caslogin')));

            // set SSL validation for the CAS server
            if ($cfg['opt_cas_validation'] == 'self') {
                phpCAS::setCasServerCert($cfg['opt_cas_cert']);
            }
            else if ($cfg['opt_cas_validation'] == 'ca') {
                phpCAS::setCasServerCACert($cfg['opt_cas_cert']);
            }
            else {
                phpCAS::setNoCasServerValidation();
            }

            // set login and logout URLs of the CAS server
            phpCAS::setServerLoginURL($cfg['opt_cas_login_url']);
            phpCAS::setServerLogoutURL($cfg['opt_cas_logout_url']);

            $this->cas_inited = true;
        }
    }
    
    /**
     * Build full URLs to this instance of RoundCube for use with CAS servers
     * 
     * @param array $params url parameters as key-value pairs
     * @return string full Roundcube URL
     */
    private function generate_url($params) {
        $s = ($_SERVER['HTTPS'] == 'on') ? 's' : '';
        $protocol = $this->strleft(strtolower($_SERVER['SERVER_PROTOCOL']), '/') . $s;
        $port = (($_SERVER['SERVER_PORT'] == '80' && $_SERVER['HTTPS'] != 'on') ||
                 ($_SERVER['SERVER_PORT'] == '443' && $_SERVER['HTTPS'] == 'on')) ? 
                '' : (':' .$_SERVER['SERVER_PORT']);
        $path = $this->strleft($_SERVER['REQUEST_URI'], '?');
        $parsed_params = '';
        $delm = '?';
        foreach (array_reverse($params) as $key => $val) {
            if (!empty($val)) {
                $parsed_key = $key[0] == '_' ? $key : '_' . $key;
                $parsed_params .= $delm . urlencode($parsed_key) . '=' . urlencode($val);
                $delm = '&';
            }
        }
        return $protocol . '://' . $_SERVER['SERVER_NAME'] . $port . $path . $parsed_params;
    }

    private function strleft($s1, $s2) {
        $length = strpos($s1, $s2);
        if ($length) {
            return substr($s1, 0, $length);
        }
        else {
            return $s1;
        }
    }
}

?>