<?php

require_once( dirname(__FILE__).'/class.OAuth.php' );
require_once( dirname(__FILE__).'/class.BitBucketAuth.php' );

/**
 *	class BitBucket
 *	contains only API functions.
 *	extends from BitBucketAuth, which have code to Auth with OAuth 1.0a
 *
 *	list of available operations
 *	- repositories: get list of repositories (my, all)
 *	- repositories: get repository info
 *	- repositories: get repository clean URL
 *	- events: commit history info
 *	- repositories: create repository
 *	- repositories: remove repository
 *  - repositories: get tags/branches list
 *  - users: register new user in bitbucket
 *  - privileges: granting privileges to user
 *  - privileges: remove privileges from user
 *  - privileges: check users with privileges
 */
class BitBucket extends BitBucketAuth{
	
	private $repository_domain = 'https://bitbucket.org';
	
	/**
	 *	get full list of repositories you own
	 */
	public function get_my_repositories(){
		
		$repos = array();
		$res = $this->request_api('GET', 'users/{username}');
		//pa($res,1);
		if( !empty($res['response']) ){
			$info = json_decode($res['response']);
			foreach($info->repositories as $repo){
				$repos[ $repo->slug ] = $repo;
			}
		}
		
		//pa($repos,1);
		return $repos;
	}
	
	/**
	 *	get list of repositories you have access to
	 */
	public function get_all_repositories(){
		$repos = array();
		$res = $this->request_api('GET', 'user/repositories');
		if( !empty($res['response']) ){
			$_repos = json_decode($res['response']);
			foreach($_repos as $repo){
				$repos[ $repo->slug ] = $repo;
			}
		}
		
		//pa($repos,1);
		return $repos;
	}
	
	/**
	 *	get repository info
	 *	@param  string  $slug     repository slug
	 *	@param  string  $username   repository owner usernmae
	 *	@return object    repository object
	 */
	public function get_repository_info( $slug, $username = '' ){
		if( empty($username) ) $username = $this->username;
		
		$res = $this->request_api('GET', 'repositories/'.$username.'/'.$slug.'/');
		if( $res['status'] == 200 ){
			$info = json_decode($res['response']);
			return $info;
		}
		
		return NULL;
	}
	
	/**
	 *	get repository info
	 *	@param  string  $slug     repository slug
	 *	@param  string  $username   repository owner usernmae
	 *	@return string    repository clone URL
	 */
	public function get_repository_clone_url( $slug, $username = '' ){
		if( empty($username) ) $username = $this->username;
		
		return $this->repository_domain.'/'.$username.'/'.$slug.'.git';
	}
	
	/**
	 *	get repository current branches and tags
	 *	@param  string  $slug     repository slug
	 *	@param  string  $username   repository owner usernmae
	 *	@return array   list of tags and branches
	 */
	public function get_repository_tree( $slug, $username = '' ){
		if( empty($username) ) $username = $this->username;
		
		$result = array(
			'branches' => array(),
			'tags' => array(),
		);
		
		$res = $this->request_api('GET', 'repositories/'.$username.'/'.$slug.'/branches/');
		if( $res['status'] == 200 ){
			$branches = json_decode($res['response']);
			foreach($branches as $branch){
				unset($branch->files);
				$result['branches'][$branch->branch] = $branch;
			}
		}

		$res = $this->request_api('GET', 'repositories/'.$username.'/'.$slug.'/tags/');
		if( $res['status'] == 200 ){
			$tags = json_decode($res['response']);
			foreach($tags as $tag){
				$result['tags'][$tag->tag] = $tag;
			}
		}
		//pa($res,1);
		
		//pa($result,1);
		return $result;
	}
	
	/**
	 *	get repository events history
	 *	@param  string  $slug     repository slug
	 *	@param  string  $username   repository owner usernmae
	 *	@param  array   $params     you can set "type" filter, "limit" or "start" number for pagination
	 *	@return array     events history array
	 */
	public function get_commit_history($slug, $username = '', $params = array()){

		$defaults = array(
			'type' => 'commit',
			'start' => '0', // offset for pagination
			'limit' => 25, // limit of rows to get
		);
		
		$params = array_merge($defaults, $params);

		if( empty($username) ) $username = $this->username;
		
		$res = $this->request_api('GET', 'repositories/'.$username.'/'.$slug.'/events/', $params);
		//pa($res,1);

		if( $res['status'] == 200 ){
			$response = json_decode($res['response']);
			if( $response->count > 0 ){
				$events = array();
				foreach($response->events as $event){
					unset($event->repository);
					$events[] = $event;
				}
				
				//pa($events);
				return $events;
			}
		}
		
		return NULL;
	}
	
	/**
	 *	create repository with scm GIT
	 *	@param  string  $name     repository nice name (slug will be created automatically)
	 *	@param  bool    $private  make repository private or not
	 *	@return Object    repository object or NULL if failed
	 */
	public function create_git_repository( $name, $private = true ){
		$params = array(
			'scm' => 'git',
			'name' => $name,
			//'owner' => $this->username,
			'is_private' => ($private)? 'True' : 'False',
		);

		$res = $this->request_api('POST', 'repositories/', $params);

		if( $res['status'] == 200 ){
			$repo = json_decode($res['response']);
			return $repo;
		}
		elseif( $res['status'] == 400 ){
			$error = strip_tags( str_replace('<ul class="errorlist"><li>', ' :: ', $res['response']) );
			throw new BitBucketException( $error );
		}
		
		return NULL;
	}
	
	/**
	 *	delete repository
	 *	you can delete only your owned repository
	 *	@param  string  $slug  repository slug to delete
	 */
	public function delete_repository( $slug ){
		$res = $this->request_api('DELETE', 'repositories/{username}/'.$slug.'/');
		if( $res['status'] == 204 ){
			// all is ok. repo deleted
			return true;
		}
		else{
			return $res['response'];
		}
	}
	
	/**
	 *	create user
	 *	@param  string  $username
	 *	@param  string  $password
	 *	@param  string  $email
	 *	@return  object   user info
	 */
	public function user_register( $username, $password, $email ){
		$params = array(
			'username' => $username,
			'password' => $password,
			'email' => $email,
		);
		
		$res = $this->request_api('POST', 'newuser/', $params);
		if( $res['status'] != 200 ){
			// we have error, return error text
			return $res['response'];
		}
		
		$user = json_decode($res['response']);
		return $user;
	}
	
	/**
	 *	add user some permissions to the project
	 *	@param  string   $repository  owned repository slug
	 *	@param  string   $user    new user to be added to the project
	 *	@param  string   $access_level    enum: read,write,admin
	 *	@return Object/Int  Object if successfull or int error code
	 */
	public function privileges_add_user($repository, $user, $access_level = 'read'){
		// this method doesn't work wit OAuth, so we need usual Auth with USERPWD field
		
		$request_url = $this->api_url . '/privileges/'.$this->username.'/'.$repository.'/'.$user;
		// need to send credentials like that, because we can't use POST - we use it for priveleges
		$curlops = array(
			'userpwd' => $this->username.':'.$this->password,
		);

		// post body is single string, no any data object!
		$params = $access_level;

		$res = $this->curl($request_url, $params, $curlops, 'PUT');
		if( $res['status'] == 200 ){
			$new_privileges = json_decode($res['response']);
			return $new_privileges;
		}
		else{
			// we have error - return error code.
			return $res['status'];
		}
	}
	
	/**
	 *	delete user from the project
	 *	@param  string   $repository  owned repository slug
	 *	@param  string   $user    new user to be added to the project
	 *	@return bool     TRUE on success
	 */
	public function privileges_delete_user($repository, $user){
		$res = $this->request_api('DELETE', 'privileges/{username}/'.$repository.'/'.$user);
		if( $res['status'] == 200 || $res['status'] == 204 ){
			return TRUE;
		}
		
		return FALSE;
	}
	
	/**
	 *	show repository granted privileges
	 *	@param  string   $repository  owned repository slug
	 *	@return array    array of granted privileges
	 */
	public function privileges_list($repository){
		
		$res = $this->request_api('GET', 'privileges/{username}/'.$repository);
		
		if( $res['status'] == 200 ){
			$privileges = array();
			$_privileges = json_decode($res['response']);
			foreach($_privileges as $rule){
				$row = $rule->user;
				$row->privilege = $rule->privilege;
				$row->repo = $rule->repo;
				
				$privileges[ $row->username ] = $row;
			}
			
			return $privileges;
		}
		
		return array();
	}
	
	/**
	 *	this is my dev test method
	 */
	public function test(  ){
		$params = array();
		$res = $this->request_api('GET', 'repositories/{username}/bitbucketoauth/events/', $params);
		pa($this->get_last_request());
		pa($res,1);
	}

}


?>