<?php
App::uses('Component', 'Controller');
App::import('Vendor', 'OAuth', array('file' => 'Bitbucket' . DS . 'class.OAuth.php'));

class BitbucketComponent extends Object {
    public $Bitbucket;
    public $key         = 'Your-Key-Here';
    public $secret      = 'Your-Secret-Here';
    
    /**
    *	basic URLs to send requests to.
    */
    protected $oauth_urls = array(
            'unauth_token' => 'https://bitbucket.org/!api/1.0/oauth/request_token/',
            'login' => 'https://bitbucket.org/!api/1.0/oauth/authenticate/',
            'access_token' => 'https://bitbucket.org/!api/1.0/oauth/access_token/',
    );
    protected $api_url = 'https://api.bitbucket.org/1.0';
    protected $callback_url = 'http://development.bitploy.com/Users/bitbucket_auth';


    protected $consumer;
    protected $signature_method;
    protected $unauth_token;
    public $access_token;

    protected $request_url;
    protected $response_data;

    //called before Controller::beforeFilter()
    public function initialize(&$controller) {
        // saving the controller reference for later use
        $this->controller = $controller;
        $this->consumer = new OAuthConsumer($this->key, $this->secret, $this->callback_url);
        $this->signature_method = new OAuthSignatureMethod_HMAC_SHA1();
    }

    //called after Controller::beforeFilter()
    public function startup(&$controller) {
    }

    //called after Controller::beforeRender()
    public function beforeRender(&$controller) {
    }

    //called after Controller::render()
    public function shutdown(&$controller) {
    }

    //called before Controller::redirect()
    public function beforeRedirect(&$controller, $url, $status=null, $exit=true) {
    }
    
    /**
     * Function to authenticate user
     * @return String
     */
    public function authenticate(){
        $unauth_request = OAuthRequest::from_consumer_and_token($this->consumer, NULL, "GET", $this->oauth_urls['unauth_token']);
        $unauth_request->set_parameter('oauth_callback', $this->callback_url, false);
        $unauth_request->sign_request($this->signature_method, $this->consumer, NULL);

        $unauth_response = $this->curl( $unauth_request->to_url() );

        $this->controller->Session->write('Bitbucket.unauth_token', $unauth_response['response']);
        $this->unauth_token = OAuthToken::from_string($unauth_response['response']);
        $auth_req = OAuthRequest::from_consumer_and_token($this->consumer, $this->unauth_token, "GET", $this->oauth_urls['login']);
        $auth_req->sign_request($this->signature_method, $this->consumer, $this->unauth_token);
        return $auth_req->to_url();
    }
    
    /**
     * Function to request Bitbucket for access token
     * @param String $oauth_verifier
     * @param String $oauth_token
     * @return String access_token
     */
    public function request_access_token($oauth_verifier, $oauth_token) {
        
        $this->unauth_token = OAuthToken::from_string($this->controller->Session->read('Bitbucket.unauth_token'));
        // request access token
        $access_token_req = OAuthRequest::from_consumer_and_token($this->consumer, $this->unauth_token, "GET", $this->oauth_urls['access_token']);
        $access_token_req->set_parameter('oauth_verifier', $oauth_verifier);
        $access_token_req->sign_request($this->signature_method, $this->consumer, $this->unauth_token);
        //pa($access_token_req,1);
        $access_token_response = $this->curl($access_token_req->to_url());
        if ($access_token_response['status'] != 200) {
            pr($access_token_response);
            return;
        }

        $token = OAuthToken::from_string($access_token_response['response']);

        return $token;
    }
    
    /**
     * Function to get all repositories of current user
     * @return Array
     */
    public function get_repositories(){
        $repos = array();
        $res = $this->request_api('GET', 'user/repositories', $params = array());
        if( !empty($res['response']) ){
                $repos = json_decode($res['response']);
        }
        return $repos;
    }
    
    /**
     * Function to get user information
     * @return Array
     */
    public function get_user(){
        $user = array();
        $res = $this->request_api('GET', 'user', $params = array());
        if( !empty($res['response']) ){
                $user = json_decode($res['response']);
        }
        return $user;
    }
    
    public function get_branches($repo_slug, $account_name){
        $branches = array();
        $res = $this->request_api('GET', 'repositories/' . $account_name . '/' . $repo_slug . '/branches', $params = array());
        if( !empty($res['response']) ){
                $branches = json_decode($res['response']);
        }
        return $branches;
    }
	
    /**
    * 	prepare url and send request
    */
    public function request_api($http_method, $uri, $params = array()) {

        // prepare request url
        $request_url = $this->api_url . '/' . $uri;
        // send request
        return $this->_request($http_method, $request_url, $params);
    }

    /**
     * 	internal request function. work with full URL set
     */
    protected function _request($http_method, $request_url, $params = array()) {
        //$request_url = str_replace('{username}', $this->username, $request_url);

        // convert params as to query string
        if ($http_method == 'GET') {
            if (!empty($params)) {
                $query_string = http_build_query($params);
                $request_url .= '?' . $query_string;
                $params = NULL;
            }
        }

        // save last request data and clean previous response data
        $this->request_data = array(
            'http_method' => $http_method,
            'url' => $request_url,
            'params' => $params,
        );
        $this->response_data = array();
        //prepare access token header
        $token = OAuthToken::from_string($this->access_token);
        $access_token_req = OAuthRequest::from_consumer_and_token($this->consumer, $token, $http_method, $request_url, $params);
        $access_token_req->sign_request($this->signature_method, $this->consumer, $token);

        // curl lib require header opt be set as array, so prepare it:
        $curl_opts = array('httpheader' => array($access_token_req->to_header()));
        $this->request_data['header'] = $curl_opts['httpheader'];

        // send request with curl
        $result = $this->curl($request_url, $params, $curl_opts, $http_method);

        $this->response_data = $result;

        return $result;
    }
    
    protected function curl($url, $params = array(), $curl_options = array(), $http_method = 'GET') {
        $ch = curl_init();

        // main curl opts
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        //curl_setopt($ch, CURLOPT_AUTOREFERER, 1); 
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        // set post and post data if needed
        if (!empty($params) || $http_method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        // set custom HTTP METHOD if needed
        if ($http_method != 'GET' && $http_method != 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);
        }

        // helper: print header send to remote server in curl_getinfo
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);

        // set all other custom headers passed with param
        foreach ($curl_options as $option => $value) {
            if (defined('CURLOPT_' . strtoupper($option))) {
                curl_setopt($ch, constant('CURLOPT_' . strtoupper($option)), $value);
            }
        }

        // send request
        $_result = curl_exec($ch);
        $_info = curl_getinfo($ch);

        $result = array(
            'response' => $_result,
            'error' => curl_error($ch),
                #'errno' => curl_errno($ch),
                #'info' => $_info,
        );
        $result['status'] = $_info['http_code'];

        return $result;
    }

}