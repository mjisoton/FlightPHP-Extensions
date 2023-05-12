<?php
/*
 *	Rate Limiter
 *	by Michel Isoton @mjisoton
 *
 *	This class allows any PHP application to protect itself from too many requests from 
 *	a single visitor/user. It uses a Redis server to keep control of the requests, ban times 
 *	and blacklist. 
 */
class RateLimiter {
	
	//Properties
	private array $request;

	//Configurations
	private array $config = array(
	
		//Time to ban the user if he exceeds the limit of requests
		'ban_time'			=> 300,
		
		//Maximum number of requests to be accepted on a certain time interval
		'max_requests'		=> 30,
		
		//Time interval to be considered when counting the user requests
		'time_interval'		=> 15,
		
		//Path to the file to be shown to the user when he exceeds the limit of requests, preferably HTML
		'page_request_ban'	=> null, 
		
		//Json paylot to return to the user when he exceeds the limit of requests, when the accept header calls for json
		'json_request_ban'	=> array(
			'error'				=> true, 
			'message'			=> 'This request was canceled due to the amount of requests done in a short interval if time. Try again after a few minutes, or contact us if you think this is a mistake.'
		)
	);
	
	/*
	 *	List of exceptions
	 *	They're URL patterns that are ignored when passing trough the rate limiter 
	 */
	private array $exceptions = array(
		'/assets/'
	);
	
	/*
	 *	Constructor
	 *	Creates the object for future use
	 */
	function __construct(private Redis $redis, array $config = null){
		
		//Checks if the Redis instance is OK
		if(!$redis):
			throw new Error('The redis instance is not available.');
			exit(1);
		endif;
	
		//Some important information about the request
		$this->request 	= array(
			'ip'		=> $_SERVER['REMOTE_ADDR'],
			'url'		=> $_SERVER['REQUEST_URI'],
			'accept'	=> (stristr($_SERVER['HTTP_ACCEPT'], 'json') !== false ? 'json' : 'html')
		);
		
		//Sets the configurations, if received 
		if($config):
			$this->config = array_merge($this->config, $config);
		endif;
		
		//With some entropy, clear the hashes 
		if(time() % 10 == 0):
			$this->clearControls();
		endif;
	}

	/*
	 *	config()
	 *	Sets the configuration for limit of requests, timing and ban time
	 */
	public function config(int $key, int $value) : void {
		$this->config[$key] = $value;
	}
	
	/*
	 *	addException()
	 *	Adds URL patterns to the list of exceptions, that shouldn't be accounter for
	 */
	public function addException(string $url) : void {
		$this->exceptions[] = $value;
	}
	
	/*
	 *	stop()
	 *	Stops the request and dies. The way it dies depends on the 
	 *	request headers
	 */
	public function stop() : void {
		
		//Sends the correct headers
		http_response_code(429);
		header('Retry-After: ', $this->config['ban_time']);
		
		//Checks the Accept header
		switch($this->request['accept']):
		
			//In case of requesting a JSON payload...
			case 'json':
				echo json_encode($this->config['json_request_ban']);
			break;
			
			//If is requesting for HTML...
			case 'html':
				if(file_exists($this->config['page_request_ban'])):
					echo file_get_contents($this->config['page_request_ban']);
				else:
					echo 'Request blocked due to abuse.';
				endif;
			break;
		endswitch;
		
		//Stops everything
		exit(0);
	}
	
	/*
	 *	consume()
	 *	This will add the request to the pool of requests of the 
	 *	current visitor, using a certain key to identify it.
	 */
	public function consume() : bool {
	
		/*
		 *	Before anything, checks if the current URL should be accounted for
		 */
		if($this->exceptions):
			foreach($this->exceptions as $e):
				if(stristr($this->request['url'], $e) !== false):
					return true;
				endif;
			endforeach;
		endif;
		
		/*
		 *	First things first: check whether the current visitor is on the blacklist 
		 *	or not. If it is, then the request should be stopped in some way 
		 */
		if($end_ban = $this->redis->hGet('RATELIMITER-BLACKLIST', $this->request['ip'])):
		
			//If the ban time is over
			if($end_ban < time()):
				$this->redis->hDel('RATELIMITER-BLACKLIST', $this->request['ip']);
				return true;
			endif;
			
			return false;
		endif;

		/*
		 *	Now, checks if there are details about the requests of this same user. If there is, 
		 *	then it should be fetched to be compared
		 */
		$requests = 0;
		if($expires = $this->redis->hGet('RATELIMITER-EXPIRES', $this->request['ip'])):
		
			//Gets the amount of request on this time interval 
			$requests = (int) $this->redis->hGet('RATELIMITER-CONTROL', $this->request['ip']);

			/*
			 *	If this IP already has a control time and it was exceeded, 
			 *	then we should check the amount of requests. If it is exdeeded, 
			 *	then inserts the IP on the blacklist, and removes the IP from 
			 *	the other hashes
			 */
			if($expires < time()):

				//If the interval was exceeded
				if($requests && $requests > $this->config['max_requests']):
					$this->redis->hSet('RATELIMITER-BLACKLIST', $this->request['ip'], time() + $this->config['ban_time']);
					$this->redis->hDel('RATELIMITER-EXPIRES', $this->request['ip']);
					$this->redis->hDel('RATELIMITER-CONTROL', $this->request['ip']);
					
					return false;
				endif;

				/*
				 *	If we are here, then the time interval has ended, but the limit of 
				 *	requests wasn't. Then, simply resets the time counter and request counter
				 */
				$this->redis->hSet('RATELIMITER-EXPIRES', $this->request['ip'], time() + $this->config['time_interval']);
				$requests = 0;
			endif;
		else:
		
			/*
			 *	It looks like it is the first request of this user. Let's 
			 *	create and entry for him and establish and end time for the 
			 *	request control
			 */
			$this->redis->hSet('RATELIMITER-EXPIRES', $this->request['ip'], time() + $this->config['time_interval']);
		endif;
		
		/*
		 *	Add the IP to the list of request control
		 */
		$this->redis->hSet('RATELIMITER-CONTROL', $this->request['ip'], $requests + 1);

		//Go on with the request
		return true;
	}
	
	/*
	 *	Clear the Redis keys used by the class when the method is called.
	 *	The cleared keys are only the expired ones.
	 */
	private function clearControls() : void {
		$expires = $this->redis->hGetAll('RATELIMITER-EXPIRES');
		
		//Iterates trough the entire hash
		foreach($expires as $k => $e):
		
			//If it's in the past
			if($e < time()):
				
				//Clear the correct keys
				$this->redis->hDel('RATELIMITER-EXPIRES', $k);
				$this->redis->hDel('RATELIMITER-CONTROL', $k);
			endif;
		endforeach;
	}
}

$redis = new Redis();
$redis->connect('127.0.0.1');

//Instance the object
$rm = new RateLimiter($redis, array(
	'ban_time'			=> 300,
	'max_requests'		=> 30,
	'time_interval'		=> 15,
	'page_request_ban'	=> './test.hmtl', 
	'json_request_ban'	=> array(
		'error'				=> true, 
		'message'			=> 'Esta requisição foi cancelada porque você excedeu o limite de requisições a esse servidor. Aguarde alguns instantes, ou contate-nos se você acha que isso é um erro.'
	)
));

if(!$rm->consume()):
	$rm->stop();
endif;

echo time();
?>
