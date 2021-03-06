<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/common/php/config.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/common/php/util.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/common/php/auth/session.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/common/php/auth/userquota.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/common/php/exportable/exportable.php');

class User extends Exportable{
	static $PRIVATE = [
		'user',
		'hash',
		'groups',
		'sessions'
	];

	static $PUBLIC = [
		'user',
		'groups',
		'sessions'
	];

	private $user = '';
	private $hash = '';
	private $groups = array();
	private $sessions = array();

	public function __construct($name = NULL) {
		/*
		*  If $name is supplied, load the userdata for the
		*  user. Otherwise do nothing.
		*/
		if (!empty($name)) { $this->load($name); }
	}

	public function __exportable_set(string $name, $value) {
		$this->{$name} = $value;
	}

	public function __exportable_get(string $name) {
		return $this->{$name};
	}

	public function load(string $user) {
		/*
		*  Load data for the user $user from file.
		*/
		$json = '';
		$data = NULL;
		$dir = NULL;

		if (empty($user)) {
			throw new ArgException('Invalid username.');
		}
		$dir = $this->get_data_dir($user);
		if (!is_dir($dir)) {
			throw new ArgException("No user named $user.");
		}
		$json = file_lock_and_get($dir.'/data.json');
		if ($json === FALSE) {
			throw new IntException('Failed to read user data!');
		}
		$data = json_decode($json, $assoc=TRUE);
		if (
			$data === NULL &&
			json_last_error() !== JSON_ERROR_NONE
		) {
			throw new IntException('JSON user data decode error!');
		}
		$this->import($data);
	}

	public function remove() {
		/*
		*  Remove the currently loaded user from the server.
		*/
		$dir = $this->get_data_dir();
		if (!is_dir($dir)) {
			throw new IntException("Userdata doesn't exist.");
		}
		if (rmdir_recursive($dir) === FALSE) {
			throw new IntException('Failed to remove userdata.');
		}
	}

	public function write() {
		/*
		*  Write the userdata into files. Returns FALSE
		*  if the maximum amount of users is exceeded and
		*  TRUE otherwise.
		*/
		$dir = $this->get_data_dir();
		$json = json_encode($this->export(TRUE, TRUE));
		if (
			$json === FALSE &&
			json_last_error() !== JSON_ERROR_NONE
		) {
			throw new IntException('Failed to JSON encode userdata!');
		}
		if (!is_dir($dir)) {
			// New user, check max users.
			if (user_count() + 1 > gtlim('MAX_USERS')) {
				return FALSE;
			}
		}
		file_lock_and_put($dir.'/data.json', $json);
		return TRUE;
	}

	function get_data_dir($user = NULL) {
		$tmp = $user;
		if ($tmp == NULL) { $tmp = $this->user; }
		return LIBRESIGNAGE_ROOT.USER_DATA_DIR.'/'.$tmp;
	}

	public function session_new(
		string $who,
		string $from,
		bool $permanent = FALSE
	) {
		/*
		*  Create a new session. This function returns an array
		*  with the keys 'session' and 'token'. 'session' contains
		*  the new Session object and 'token' contains the generated
		*  session token.
		*/
		$session = new Session();
		$token = $session->new($this, $who, $from, $permanent);
		$this->sessions[] = $session;
		return [
			'session' => $session,
			'token' => $token
		];
	}

	public function session_rm(string $id) {
		/*
		*  Remove an existing session with the session ID 'id'.
		*/
		foreach ($this->sessions as $i => $s) {
			if ($s->get_id() === $id) {
				array_splice($this->sessions, $i, 1);
				$this->sessions = array_values($this->sessions);
				return;
			}
		}
		throw new ArgException("No such session.");
	}

	public function session_n_rm(string $id) {
		/*
		*  'Negated' session_rm(). Remove all sessions except
		*  the session corresponding to the session ID 'id'.
		*/
		$s_new = $this->sessions;
		foreach ($s_new as $i => $s) {
			if ($s->get_id() !== $id) { $s_new[$i] = NULL; }
		}
		$this->sessions = array_values(array_filter($s_new));
	}

	public function session_token_verify(string $token) {
		/*
		*  Verify a session token against the sessions of
		*  this user. If a session matches the token, the
		*  Session object for the matching session is returned.
		*  This function also purges all expired sessions and
		*  writes the changes to disk.
		*/
		$s_new = $this->sessions;
		$ret = NULL;
		foreach ($this->sessions as $k => $s) {
			if ($s->is_expired()) {
				$s_new[$k] = NULL;
				continue;
			}
			if ($ret === NULL && $s->verify($token)) { $ret = $s; }
		}
		$this->sessions = array_values(array_filter($s_new));
		$this->write();
		return $ret;
	}

	public function session_get(string $id) {
		/*
		*  Get a session by its ID. NULL is returned if
		*  a session with the supplied ID doesn't exist.
		*/
		foreach ($this->sessions as $i => $s) {
			if ($s->get('id') === $id) { return $s; }
		}
		return NULL;
	}

	public function set_sessions($sessions) {
		/*
		*  Set the Session object array.
		*/
		$this->sessions = array_values($sessions);
	}

	public function get_sessions() {
		/*
		*  Get the Session object array.
		*/
		return $this->sessions;
	}

	public function get_groups() {
		return $this->groups;
	}

	public function is_in_group(string $group) {
		return in_array($group, $this->groups, TRUE);
	}

	public function set_groups($groups) {
		if ($groups == NULL) {
			$this->groups = [];
		} else if (gettype($groups) == 'array') {
			if (count($groups) > gtlim('MAX_USER_GROUPS')) {
				throw new ArgException('Too many user groups.');
			}
			$this->groups = $groups;
		} else {
			throw new ArgException("Invalid type for $groups.");
		}
	}

	public function verify_password(string $pass) {
		return password_verify($pass, $this->hash);
	}

	public function set_password(string $password) {
		if (strlen($password) > gtlim('PASSWORD_MAX_LEN')) {
			throw new ArgException('Password too long.');
		}

		$tmp_hash = password_hash($password, PASSWORD_DEFAULT);
		if ($tmp_hash === FALSE) {
			throw new IntException('Password hashing failed.');
		}

		$this->hash = $tmp_hash;
	}

	public function set_hash(string $hash) {
		if (empty($hash)) {
			throw new ArgException('Invalid password hash.');
		}
		$this->hash = $hash;
	}

	public function get_hash() {
		return $this->hash;
	}

	public function set_name(string $name) {
		if (empty($name)) {
			throw new ArgException('Invalid username.');
		}
		if (strlen($name) > gtlim('USERNAME_MAX_LEN')) {
			throw new ArgException('Username too long.');
		}
		$this->user = $name;
	}

	public function get_name() {
		return $this->user;
	}
}

function user_exists(string $user) {
	/*
	*  Check whether $user exists.
	*/
	try {
		new User($user);
	} catch (ArgException $e) {
		return FALSE;
	}
	return TRUE;
}

function user_name_array() {
	/*
	*  Get an array of all the existing usernames.
	*/
	$user_dirs = @scandir(LIBRESIGNAGE_ROOT.USER_DATA_DIR);
	if ($user_dirs === FALSE) {
		throw new IntException('scandir() on users dir failed.');
	}
	$user_dirs = array_diff($user_dirs, ['.', '..']);
	foreach ($user_dirs as $k => $d) {
		if (!user_exists($d)) { $user_dirs[$k] = NULL; }
	}
	return array_values(array_diff($user_dirs, array(NULL)));
}

function user_array() {
	/*
	*  Get an array of all the existing user objects.
	*/
	$names = user_name_array();
	$ret = array();
	foreach ($names as $n) { array_push($ret, new User($n)); }
	return $ret;
}

function user_count() {
	/*
	*  Get the number of existing users.
	*/
	return count(user_array());
}
