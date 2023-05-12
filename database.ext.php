<?php
/*
 *	Database
 *	by Michel Isoton @mjisoton
 *
 *	This class helps your PHP application to deal with relational databases. It focuses 
 *	on MySQL and MariaDB, but since it uses PDO, probably can be used for most databases
 */
class Database {
	
	//PDO instance 
	private ?PDO $pdo = null;
	
	//Default credentials
	private array $credentials = array(
		'ip'		=> null, 
		'socket'	=> null,
		'port'		=> 3306,
		'user'		=> '', 
		'pass'		=> '',
		'charset'	=> 'utf8'
	);	
	
	//Default database connection configurations
	private array $database_config = array(
		PDO::ATTR_DEFAULT_FETCH_MODE 	=> PDO::FETCH_OBJ, 
		PDO::ATTR_ERRMODE 				=> PDO::ERRMODE_EXCEPTION, 
		PDO::ATTR_EMULATE_PREPARES 		=> false,
		PDO::ATTR_PERSISTENT 			=> true
	);
	
	//Default class configuration
	private array $config = array(
		
		//Path to the file to be shown to the user when the database connection fails, preferably HTML
		'page_connect_error'	=> null, 
		
		//JSON payload to return to the user when the database connection fails. Used only when the accept header calls for JSON
		'json_connect_error'	=> array(
			'error'					=> true, 
			'message'				=> 'The connection to the database failed. This could be temporary. Please, wait a few minutes, then try again.'
		)
	);
	
	/*
	 *	Constructor
	 *	Creates the object for use
	 */
	function __construct(array $credentials, array $database_config = null) {
		
		//If a connections was already established, the only returns 
		if($this->pdo):
			return $this->pdo;
		endif;
		
		//Sets the credentials, if received, merging with the defaults
		if($credentials):
			$this->credentials = array_merge($this->credentials, $credentials);
		endif;	
		
		//Sets the configurations, if received, merging with the defaults
		if($database_config):
			$this->database_config = array_merge($this->database_config, $database_config);
		endif;
		
		//Connects 
		$this->pdo = $this->establishConnection();
		
		//In case the connection failed
		if(!$this->pdo):
			return false;
		endif;
		
		//Returns
		return $this->pdo;
	}
	
	/*
	 *	getInstance
	 *	Just returns the PDO instance
	 */
	public function getInstance() : PDO {
		return $this->pdo;
	}

	/*
	 *	establishConnection()
	 *	Connects to the database
	 */
	private function establishConnection() : ?PDO {
		
		/*
		 *	First of all, check if the credentials provided were for TCP connection, 
		 *	or UNIX socket. Always prefer UNIX Sockets, since they are way faster
		 */
		if($this->credentials['socket']):
		
			//DSN for UNIX socket...
			$dsn = 'mysql:dbname='. $this->credentials['database'] .';unix_socket'. $this->credentials['socket'] .';charset='. $this->credentials['charset'];
			
		elseif($this->credentials['ip']):
		
			//DSN for TCP Connection...
			$dsn = 'mysql:dbname='. $this->credentials['database'] .';host='. $this->credentials['ip'] .';port='. $this->credentials['port'] .';charset='. $this->credentials['charset'];
			
		else:
			
			//If it couldn't identify the correct way of connecting...
			return null;
		endif;
		
		/*
		 *	Puts everything inside a trycatch block, since there are too many 
		 *	possible errors
		 */
		try {
			
			//Creates the instance and connects
			$pdo = new PDO($dsn, $this->credentials['user'], $this->credentials['pass'], $this->database_config);
		
		} catch(PDOException $ex){
			
			//Errors...
			$pdo = null;
		}
		
		//Returns the instance
		return $pdo;
	}
	
	/*
	 *	isConnected()
	 *	Checks if the connection to the relational database was successfully established
	 */
	public function isConnected() : bool {
		return isset($this->pdo);
	}
	
	/*
	 *	stop()
	 *	Stops the request and dies. The way it dies depends on the 
	 *	request headers
	 */
	public function stop() : void {
		
		//Sends the correct headers
		http_response_code(500);
		
		//Checks the 'Accept' header 
		$accept = stristr($_SERVER['HTTP_ACCEPT'], 'json') !== false ? 'json' : 'html';

		//Checks the Accept header
		switch($accept):
		
			//In case of requesting a JSON payload...
			case 'json':
			
				header('Content-Type: application/json');
				echo json_encode($this->config['json_connect_error']);
			break;
			
			//If is requesting for HTML...
			case 'html':
				if(file_exists($this->config['page_connect_error'])):
				
					header('Content-Type: text/html');
					echo file_get_contents($this->config['page_connect_error']);
				else:
					echo 'The database is down. Try again in a few minutes.';
				endif;
			break;
		endswitch;
		
		//Stops everything
		exit(0);
	}
}



//Instance the object
$db = new Database(array(
	'ip'		=> 'localhost', 
	'socket'	=> null,
	'port'		=> 3306,
	'user'		=> 'root', 
	'pass'		=> '',
	'database'	=> 'agencianet_clientes'
), array(
	PDO::ATTR_DEFAULT_FETCH_MODE 	=> PDO::FETCH_ASSOC, 
	PDO::ATTR_ERRMODE 				=> PDO::ERRMODE_EXCEPTION, 
	PDO::ATTR_EMULATE_PREPARES 		=> false,
	PDO::ATTR_PERSISTENT 			=> true
), array(
	'page_connect_error'	=> 'test.html', 
	'json_connect_error'	=> array(
		'error'	=> true, 
		'mensagem'	=> 'O servidor de banco de dados está inacessível. Tente novamente em alguns minutos.'
	)
));

if(!$db->isConnected()):
	$db->stop();
endif;

$db = $db->getInstance();

echo time();
?>
