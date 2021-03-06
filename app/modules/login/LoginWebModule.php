<?php
/**
  * @package Module
  * @subpackage Login
  */

/**
  * @package Module
  * @subpackage Login
  */
class LoginWebModule extends WebModule {
    protected $id = 'login';
    protected $defaultAllowRobots = false; // Require sites to intentionally turn this on
  
    // ensure that the login module always has access 
    protected function getAccessControlLists($type) {
        return array(AccessControlList::allAccess());
    }
  
    public function isEnabled() {
        return Kurogo::getSiteVar('AUTHENTICATION_ENABLED') && parent::isEnabled();
    }

    protected function extractModuleArray($args) {
        $return = array();
        if (isset($args['id'])) {
            $return['id'] = $args['id'];
        }

        if (isset($args['page'])) {
            $return['page'] = $args['page'];
        }

        if (isset($args['args'])) {
            $return['args'] = $args['args'];
        }
        
        return $return;
    }

  protected function initializeForPage() {
    $nativeApp = (bool) $this->getArg('nativeApp', false);
    $this->assign('nativeApp', $nativeApp);

    // Default args to pass through forms and urls
    $defaultArgs = array();
    if ($nativeApp) {
        $defaultArgs['nativeApp'] = 1;
    }
    
    // If this is a native app, use the native app GA id
    if ($nativeApp) {
        $this->assign('GOOGLE_ANALYTICS_ID', 
          Kurogo::getOptionalSiteVar('GOOGLE_ANALYTICS_NATIVE_ID'));
    }
    
    if (!Kurogo::getSiteVar('AUTHENTICATION_ENABLED')) {
        throw new KurogoConfigurationException($this->getLocalizedString("ERROR_AUTHENTICATION_DISABLED"));
    }
    
    $session = $this->getSession();
    
    //return URL
    $urlArray = $this->extractModuleArray($this->args);
    
    //see if remain logged in is enabled by the administrator, then if the value has been passed (i.e. the user checked the "remember me" box)
    $allowRemainLoggedIn = Kurogo::getOptionalSiteVar('AUTHENTICATION_REMAIN_LOGGED_IN_TIME');
    if ($allowRemainLoggedIn) {
        $remainLoggedIn = $this->getArg('remainLoggedIn', 0);
    } else {
        $remainLoggedIn = 0;
    }
    
    // initialize
    $authenticationAuthorities = array(
        'total'=>0,
        'direct'=>array(),
        'indirect'=>array(),
        'auto'=>array()
    );
    
    $invalidAuthorities = array();
    
    // cycle through the defined authorities in the config
    foreach (AuthenticationAuthority::getDefinedAuthenticationAuthorities() as $authorityIndex=>$authorityData) {
        // USER_LOGIN property determines whether the authority is used for logins (or just groups or oauth)
        $USER_LOGIN = $this->argVal($authorityData, 'USER_LOGIN', 'NONE');

        // trap the exception if the authority is invalid (usually due to misconfiguration)
        try {
            $authority = AuthenticationAuthority::getAuthenticationAuthority($authorityIndex);
            $authorityData['listclass'] = $authority->getAuthorityClass();
            $authorityData['title'] = $authorityData['TITLE'];
            $authorityData['url'] = $this->buildURL('login', array_merge($urlArray, array(
                'authority'=>$authorityIndex,
                'remainLoggedIn'=>$remainLoggedIn,
                'startOver'=>1
            )));

            if ($USER_LOGIN=='FORM') {
                $authenticationAuthorities['direct'][$authorityIndex] = $authorityData;
                $authenticationAuthorities['total']++;
            } elseif ($USER_LOGIN=='LINK') {
                $authenticationAuthorities['indirect'][$authorityIndex] = $authorityData;
                $authenticationAuthorities['total']++;
            } elseif ($USER_LOGIN=='AUTO') {
                $authenticationAuthorities['auto'][$authorityIndex] = $authorityData;
                $authenticationAuthorities['total']++;
            }
        } catch (KurogoConfigurationException $e) {
            Kurogo::log(LOG_WARNING, "Invalid authority data for %s: %s", $authorityIndex, $e->getMessage(), 'auth');
            $invalidAuthorities[$authorityIndex] = $e->getMessage();
        }
    }
                 
    //see if we have any valid authorities
    if ($authenticationAuthorities['total']==0) {
        $message = $this->getLocalizedString("ERROR_NO_AUTHORITIES");
        if (count($invalidAuthorities)>0) {
            $message .= sprintf(" %s invalid authorit%s found:\n", count($invalidAuthorities), count($invalidAuthorities)>1 ?'ies':'y');
            foreach ($invalidAuthorities as $authorityIndex=>$invalidAuthority) {
                $message .= sprintf("%s: %s\n", $authorityIndex, $invalidAuthority);
            }
        }
        
        //we don't
        throw new KurogoConfigurationException($message);
        
    }
    
    //assign template variables
    $this->assign('authenticationAuthorities', $authenticationAuthorities);
    $this->assign('allowRemainLoggedIn', $allowRemainLoggedIn);
    if ($forgetPasswordURL = $this->getOptionalModuleVar('FORGET_PASSWORD_URL')) {
        $this->assign('FORGET_PASSWORD_URL', $this->buildBreadcrumbURL('forgotpassword', array()));
        $this->assign('FORGET_PASSWORD_TEXT', $this->getOptionalModuleVar('FORGET_PASSWORD_TEXT', $this->getLocalizedString('FORGET_PASSWORD_TEXT')));
    }
    
    $multipleAuthorities = count($authenticationAuthorities['direct']) + count($authenticationAuthorities['indirect']) > 1;
    
    switch ($this->page)
    {
        case 'logoutConfirm':
            //this page is presented when a specific authority is chosen and the user is presented the option to actually log out.
            $authorityIndex = $this->getArg('authority');
            
            if (!$this->isLoggedIn($authorityIndex)) {
                // they aren't logged in
                $this->redirectTo('index', $defaultArgs);
            } elseif ($user = $this->getUser($authorityIndex)) {
                $authority = $user->getAuthenticationAuthority();
                
                $this->assign('message', $this->getLocalizedString('LOGIN_SIGNED_IN_SINGLE',
                    Kurogo::getSiteString('SITE_NAME'),
                    $authority->getAuthorityTitle(), 
                    $user->getFullName()
                ));
                
                $this->assign('url', $this->buildURL('logout', array('authority'=>$authorityIndex)));
                $this->assign('linkText', $this->getLocalizedString('SIGN_OUT'));
                $this->setTemplatePage('message');
            } else {
                //This honestly should never happen
                $this->redirectTo('index', $defaultArgs);
            }
            
            break;
        case 'logout':
            $authorityIndex = $this->getArg('authority');
            //hard logouts attempt to logout of the indirect service provider (must be implemented by the authority)
            $hard = $this->getArg('hard', false);

            if (!$this->isLoggedIn($authorityIndex)) {
                //not logged in
                $this->redirectTo('index', $defaultArgs);
            } elseif ($authority = AuthenticationAuthority::getAuthenticationAuthority($authorityIndex)) {
                $user = $this->getUser($authority);

                //log them out 
                $result = $session->logout($authority, $hard);
            } else {
                //This honestly should never happen
                $this->redirectTo('index', $defaultArgs);
            }
                
            if ($result) { 
                $this->setLogData($user, $user->getFullName());
                $this->logView();

                //if they are still logged in return to the login page, otherwise go home.
                if ($this->isLoggedIn()) {
                    $this->redirectTo('index', array_merge(array('logout'=>$authorityIndex), $defaultArgs));
                } else {
                    $this->redirectToModule($this->getHomeModuleID(),'',array('logout'=>$authorityIndex));
                }
            } else {
                //there was an error logging out
                $this->setTemplatePage('message');
                $this->assign('message', $this->getLocalizedString("ERROR_SIGN_OUT"));
            }
        
            break;

        case 'forgotpassword':
            //redirect to forgot password url
            if ($forgetPasswordURL = $this->getOptionalModuleVar('FORGET_PASSWORD_URL')) {
                Kurogo::redirectToURL($forgetPasswordURL);
            } else {
                $this->redirectTo('index', $defaultArgs);
            }
            break;            
            
        case 'login':
            //get arguments
            $login          = $this->argVal($_POST, 'loginUser', '');
            $password       = $this->argVal($_POST, 'loginPassword', '');
            $options = array_merge($urlArray, array(
                'remainLoggedIn'=>$remainLoggedIn
            ), $defaultArgs);
            
            $session  = $this->getSession();
            $session->setRemainLoggedIn($remainLoggedIn);

            $authorityIndex = $this->getArg('authority', '');
            if (!$authorityData = AuthenticationAuthority::getAuthenticationAuthorityData($authorityIndex)) {
                //invalid authority
                $this->redirectTo('index', $options);
            }

            if ($this->isLoggedIn($authorityIndex)) {
                //we're already logged in
                $this->redirectTo('index', $options);
            }                    

            $this->assign('authority', $authorityIndex);
            $this->assign('remainLoggedIn', $remainLoggedIn);
            $this->assign('authorityTitle', $authorityData['TITLE']);

            //if they haven't submitted the form and it's a direct login show the form
            if ($authorityData['USER_LOGIN']=='FORM' && empty($login)) {

                if (!$loginMessage = $this->getOptionalModuleVar('LOGIN_DIRECT_MESSAGE')) {
                    $loginMessage = $this->getLocalizedString('LOGIN_DIRECT_MESSAGE', Kurogo::getSiteString('SITE_NAME'));
                }
                $this->assign('LOGIN_DIRECT_MESSAGE', $loginMessage);
                $this->assign('urlArray', array_merge($urlArray, $defaultArgs));
                break;
            } elseif ($authority = AuthenticationAuthority::getAuthenticationAuthority($authorityIndex)) {
                //indirect logins handling the login process themselves. Send a return url so the indirect authority can come back here
                if ($authorityData['USER_LOGIN']=='LINK') {
                    $options['return_url'] = FULL_URL_BASE . $this->configModule . '/login?' . http_build_query(array_merge($options, array(
                            'authority'=>$authorityIndex
                    )));
                }
                $options['startOver'] = $this->getArg('startOver', 0);

                $result = $authority->login($login, $password, $session, $options);
            } else {
                $this->redirectTo('index', $options);
            }

            switch ($result)
            {
                case AUTH_OK:
                    $user = $this->getUser($authority);
                    $this->setLogData($user, $user->getFullName());
                    $this->logView();
                    if ($urlArray) {
                        self::redirectToArray(array_merge($urlArray, $defaultArgs));
                    } else {
                        $this->redirectToModule($this->getHomeModuleID(),'',array('login'=>$authorityIndex));
                    }
                    break;

                case AUTH_OAUTH_VERIFY:
                    // authorities that require a manual oauth verification key 
                    $this->assign('verifierKey',$authority->getVerifierKey());
                    $this->setTemplatePage('oauth_verify.tpl');
                    break 2;
                    
                default:
                    //there was a problem.
                    if ($authorityData['USER_LOGIN']=='FORM') {
                        $this->assign('message', $this->getLocalizedString('ERROR_LOGIN_DIRECT'));
                        break 2;
                    } else {
                        $this->redirectTo('index', array_merge(
                            array('messagekey'=>'ERROR_LOGIN_INDIRECT'),
                            $options, $defaultArgs));
                    }
            }
            
        case 'index':
            //sometimes messages are passed. This probably has some 
            if ($messagekey = $this->getArg('messagekey')) {
                $this->assign('messagekey', $this->getLocalizedString($messagekey));
                try {
                    $message = $this->getLocalizedString($messagekey);
                    $this->assign('message', $message);
                } catch (KurogoException $e) {
                }
            }
            
            if ($this->isLoggedIn()) {
            
                //if the url is set then redirect
                if ($urlArray) {
                    self::redirectToArray(array_merge($urlArray, $defaultArgs));
                }

                //if there is only 1 authority then redirect to logout confirm
                if (!$multipleAuthorities) {
                    $user = $this->getUser();
                    $this->redirectTo('logoutConfirm', array_merge(array('authority'=>$user->getAuthenticationAuthorityIndex()), $defaultArgs));
                }

                //more than 1 authority. There could be 1 or more actual logged in users
                $sessionUsers = $session->getUsers();
                $users = array();

                //cycle through the logged in users to build a list
                foreach ($sessionUsers as $authorityIndex=>$user) { 
                    $authority = $user->getAuthenticationAuthority();
                    $users[] = array(
                        'class'=>$authority->getAuthorityClass(),
                        'title'=>count($sessionUsers)>1 ? $this->getLocalizedString("SIGN_OUT_AUTHORITY", array($authority->getAuthorityTitle(), $user->getFullName())) : $this->getLocalizedString('SIGN_OUT'),
                        'subtitle'=>count($sessionUsers)>1 ? $this->getLocalizedString('SIGN_OUT') : '',
                        'url'  =>$this->buildBreadcrumbURL('logout', array('authority'=>$authorityIndex), false)
                    );
                    
                    //remove the authority from the list of available authorities (since they are logged in)
                    if (isset($authenticationAuthorities['direct'][$authorityIndex])) {
                        unset($authenticationAuthorities['direct'][$authorityIndex]);
                    }

                    if (isset($authenticationAuthorities['indirect'][$authorityIndex])) {
                        unset($authenticationAuthorities['indirect'][$authorityIndex]);
                    }
                }
                
                $this->assign('users', $users); // navlist of users
                $this->assign('authenticationAuthorities', $authenticationAuthorities); //list of authorities not logged in
                $this->assign('moreAuthorities', count($authenticationAuthorities['direct']) + count($authenticationAuthorities['indirect'])); //see if there are any left
                
                if (count($sessionUsers)==1) {
                    //there's only on logged in user
                    $user = current($sessionUsers);
                    $authority = $user->getAuthenticationAuthority();
                    $this->assign('LOGIN_SIGNED_IN_MESSAGE', $this->getLocalizedString('LOGIN_SIGNED_IN_SINGLE',
                        Kurogo::getSiteString('SITE_NAME'), 
                        $authority->getAuthorityTitle(), 
                        $user->getFullName()
                    ));
                } else {
                    //there are multiple logged in users
                    $this->assign('LOGIN_SIGNED_IN_MESSAGE', $this->getLocalizedString('LOGIN_SIGNED_IN_MULTIPLE', array(Kurogo::getSiteString('SITE_NAME'))));
                }

                //use loggedin.tpl
                $this->setTemplatePage('loggedin');
            } else { // not logged in
            
                // if there is only 1 direct authority then redirect to the login page for that authority
                if (!$multipleAuthorities && count($authenticationAuthorities['direct'])) {
                    $this->redirectTo('login', array_merge($urlArray, array('authority'=>key($authenticationAuthorities['direct'])), $defaultArgs));
                }

                // if there is only 1 auto authority then redirect to the login page for that authority
                if (!$multipleAuthorities && count($authenticationAuthorities['auto']) && !$messagekey) {
                    $this->redirectTo('login', array_merge($urlArray, array('authority'=>key($authenticationAuthorities['auto'])), $defaultArgs));
                }
                
                // do we have any indirect authorities?
                if (count($authenticationAuthorities['indirect'])) {
                    if (!$indirectMessage = $this->getOptionalModuleVar('LOGIN_INDIRECT_MESSAGE')) {
                        $indirectMessage = $this->getLocalizedString('LOGIN_INDIRECT_MESSAGE', Kurogo::getSiteString('SITE_NAME'));
                    }
                    $this->assign('LOGIN_INDIRECT_MESSAGE', $indirectMessage);
                }
                
                // the site can create their own message at the top, or it will use the default message
                if (!$loginMessage = $this->getOptionalModuleVar('LOGIN_INDEX_MESSAGE')) {
                    if ($multipleAuthorities) {
                        $loginMessage = $this->getLocalizedString('LOGIN_INDEX_MESSAGE_MULTIPLE', Kurogo::getSiteString('SITE_NAME'));
                    }  else {
                        $loginMessage = $this->getLocalizedString('LOGIN_INDEX_MESSAGE_SINGLE', Kurogo::getSiteString('SITE_NAME'));
                    }
                }
                
                $this->assign('LOGIN_INDEX_MESSAGE',$loginMessage);
            }
            break;
    }
  }

}

