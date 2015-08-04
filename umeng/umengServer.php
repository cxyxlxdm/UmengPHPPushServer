<?php
/**
 * @file
 * UmengPHP_Push_Server class definition.
 */

/**
 * @defgroup UmengPHP_Push_Server Server
 * @ingroup UmengPHP_Push
 */

/**
 * The Push Notification Server Provider.
 *
 * The class manages multiple Push Notification Providers and an inter-process message
 * queue. This class is useful to parallelize and speed-up send activities to Apple
 * Push Notification service.
 *
 * @ingroup UmengPHP_Push_Server
 */
require_once(dirname(__FILE__) . '/src/' . 'notification/android/AndroidListcast.php');

class UmengPHP_Push_Server_Exception extends Exception
{
}

class UmengPHP_Message
{
    protected $_sDeviceToken; /**< @type string Recipient device token. 逗号分割的设备token */
    protected $_sText; /**< @type string Alert message to display to the user. */
    
    /**
    * Set the alert message to display to the user.
    *
    * @param  $sText @type string An alert message to display to the user.
    */
    public function setText($sText)
    {
           $this->_sText = $sText;
    }

    /**
    * Get the alert message to display to the user.
    *
    * @return @type string The alert message to display to the user.
    */
    public function getText()
    {
           return $this->_sText;
    }
    
    public function setDeviceToken($sDeviceToken)
    {
           $this->_sDeviceToken = $sDeviceToken;
    }

    public function getDeviceToken()
    {
           return $this->_sDeviceToken;
    }
}

class UmengPHP_Push_Server
{
	const MAIN_LOOP_USLEEP = 200000; /**< @type integer Main loop sleep time in micro seconds. */
	const SHM_SIZE = 524288; /**< @type integer Shared memory size in bytes useful to store message queues. */
	const SHM_MESSAGES_QUEUE_KEY_START = 1000; /**< @type integer Message queue start identifier for messages. For every process 1 is added to this number. */
	const SHM_ERROR_MESSAGES_QUEUE_KEY = 999; /**< @type integer Message queue identifier for not delivered messages. */

        const UMENG_SHM_KEY = 9897624899;
        const UMENG_MEM_KEY = 9897624884;
        
	protected $_nProcesses = 3; /**< @type integer The number of processes to start. */
	protected $_aPids = array(); /**< @type array Array of process PIDs. */
	protected $_nParentPid; /**< @type integer The parent process id. */
	protected $_nCurrentProcess; /**< @type integer Cardinal process number (0, 1, 2, ...). */
	protected $_nRunningProcesses; /**< @type integer The number of running processes. */

	protected $_hShm; /**< @type resource Shared memory. */
	protected $_hSem; /**< @type resource Semaphore. */
        
        protected $appkey           = NULL; 
	protected $appMasterSecret     = NULL;
	protected $timestamp        = NULL;

	/**
	 * Constructor.
	 *
	 * @param  $nEnvironment @type integer Environment.
	 * @param  $sProviderCertificateFile @type string Provider certificate file
	 *         with key (Bundled PEM).
	 * @throws UmengPHP_Push_Server_Exception if is unable to
	 *         get Shared Memory Segment or Semaphore ID.
	 */
	public function __construct($key, $secret)
	{
            $this->appkey = $key;
            $this->appMasterSecret = $secret;
            $this->timestamp = strval(time());
                
		$this->_nParentPid = posix_getpid();
		//$this->_hShm = shm_attach(mt_rand(), self::SHM_SIZE);
                $this->_hShm = shm_attach(self::UMENG_SHM_KEY, self::SHM_SIZE);
		if ($this->_hShm === false) {
			throw new UmengPHP_Push_Server_Exception(
				'Unable to get shared memory segment'
			);
		}

		//$this->_hSem = sem_get(mt_rand());
                $this->_hSem = sem_get(self::UMENG_MEM_KEY);
		if ($this->_hSem === false) {
			throw new UmengPHP_Push_Server_Exception(
				'Unable to get semaphore id'
			);
		}

		register_shutdown_function(array($this, 'onShutdown'));

		pcntl_signal(SIGCHLD, array($this, 'onChildExited'));
		foreach(array(SIGTERM, SIGQUIT, SIGINT) as $nSignal) {
			pcntl_signal($nSignal, array($this, 'onSignal'));
		}
	}

        public function _log($sMessage){
            printf("%s ApnsPHP[%d]: %s\n",
		date('r'), getmypid(), trim($sMessage)
            );
        }
	/**
	 * Checks if the server is running and calls signal handlers for pending signals.
	 *
	 * Example:
	 * @code
	 * while ($Server->run()) {
	 *     // do somethings...
	 *     usleep(200000);
	 * }
	 * @endcode
	 *
	 * @return @type boolean True if the server is running.
	 */
	public function run()
	{
		pcntl_signal_dispatch();
		return $this->_nRunningProcesses > 0;
	}

	/**
	 * Waits until a forked process has exited and decreases the current running
	 * process number.
	 */
	public function onChildExited()
	{
		while (pcntl_waitpid(-1, $nStatus, WNOHANG) > 0) {
			$this->_nRunningProcesses--;
		}
	}

	/**
	 * When a child (not the parent) receive a signal of type TERM, QUIT or INT
	 * exits from the current process and decreases the current running process number.
	 *
	 * @param  $nSignal @type integer Signal number.
	 */
	public function onSignal($nSignal)
	{
		switch ($nSignal) {
			case SIGTERM:
			case SIGQUIT:
			case SIGINT:
				if (($nPid = posix_getpid()) != $this->_nParentPid) {
					$this->_log("INFO: Child $nPid received signal #{$nSignal}, shutdown...");
					$this->_nRunningProcesses--;
					exit(0);
				}
				break;
			default:
				$this->_log("INFO: Ignored signal #{$nSignal}.");
				break;
		}
	}

	/**
	 * When the parent process exits, cleans shared memory and semaphore.
	 *
	 * This is called using 'register_shutdown_function' pattern.
	 * @see http://php.net/register_shutdown_function
	 */
	public function onShutdown()
	{
		if (posix_getpid() == $this->_nParentPid) {
			$this->_log('INFO: Parent shutdown, cleaning memory...');
			@shm_remove($this->_hShm) && @shm_detach($this->_hShm);
			@sem_remove($this->_hSem);
		}
	}

	/**
	 * Set the total processes to start, default is 3.
	 *
	 * @param  $nProcesses @type integer Processes to start up.
	 */
	public function setProcesses($nProcesses)
	{
		$nProcesses = (int)$nProcesses;
		if ($nProcesses <= 0) {
			return;
		}
		$this->_nProcesses = $nProcesses;
	}

	/**
	 * Starts the server forking all processes and return immediately.
	 *
	 * Every forked process is connected to Apple Push Notification Service on start
	 * and enter on the main loop.
	 */
	public function start()
	{
		for ($i = 0; $i < $this->_nProcesses; $i++) {
			$this->_nCurrentProcess = $i;
			$this->_aPids[$i] = $nPid = pcntl_fork();
			if ($nPid == -1) {
				$this->_log('WARNING: Could not fork');
			} else if ($nPid > 0) {
				// Parent process
				$this->_log("INFO: Forked process PID {$nPid}");
				$this->_nRunningProcesses++;
			} else {
				// Child process
				$this->_mainLoop();
				exit(0);
			}
		}
	}

	/**
	 * Adds a message to the inter-process message queue.
	 *
	 * Messages are added to the queues in a round-robin fashion starting from the
	 * first process to the last.
	 *
	 * @param  $message @type UmengPHP_Message The message.
	 */
	public function add(UmengPHP_Message $message)
	{
		static $n = 0;
		if ($n >= $this->_nProcesses) {
			$n = 0;
		}
		sem_acquire($this->_hSem);
		$aQueue = $this->_getQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $n);
		$aQueue[] = $message;
		$this->_setQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $n, $aQueue);
		sem_release($this->_hSem);
		$n++;
	}

	/**
	 * Returns messages in the message queue.
	 *
	 * When a message is successful sent or reached the maximum retry time is removed
	 * from the message queue and inserted in the Errors container. Use the getErrors()
	 * method to retrive messages with delivery error(s).
	 *
	 * @param  $bEmpty @type boolean @optional Empty message queue.
	 * @return @type array Array of messages left on the queue.
	 */
	public function getQueue($bEmpty = true)
	{
		$aRet = array();
		sem_acquire($this->_hSem);
		for ($i = 0; $i < $this->_nProcesses; $i++) {
			$aRet = array_merge($aRet, $this->_getQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $i));
			if ($bEmpty) {
				$this->_setQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $i);
			}
		}
		sem_release($this->_hSem);
		return $aRet;
	}

	/**
	 * Returns messages not delivered to the end user because one (or more) error
	 * occurred.
	 *
	 * @param  $bEmpty @type boolean @optional Empty message container.
	 * @return @type array Array of messages not delivered because one or more errors
	 *         occurred.
	 */
	public function getErrors($bEmpty = true)
	{
		sem_acquire($this->_hSem);
		$aRet = $this->_getQueue(self::SHM_ERROR_MESSAGES_QUEUE_KEY);
		if ($bEmpty) {
			$this->_setQueue(self::SHM_ERROR_MESSAGES_QUEUE_KEY, 0, array());
		}
		sem_release($this->_hSem);
		return $aRet;
	}

        
        private function _pushAndroidMessage($message){
            try {
                    $brocast = new AndroidListcast();
                    $brocast->setAppMasterSecret($this->appMasterSecret);
                    $brocast->setPredefinedKeyValue("appkey",           $this->appkey);
                    $brocast->setPredefinedKeyValue("timestamp",        $this->timestamp);
                    $brocast->setPredefinedKeyValue("ticker",           "Android broadcast ticker");
                    $brocast->setPredefinedKeyValue("title",            "中文的title");
                    $brocast->setPredefinedKeyValue("text",             $message->getText());
                    $brocast->setPredefinedKeyValue("after_open",       "go_app");
                    // Set 'production_mode' to 'false' if it's a test device. 
                    // For how to register a test device, please see the developer doc.
                    $brocast->setPredefinedKeyValue("device_tokens",        $message->getDeviceToken());
                    $brocast->setPredefinedKeyValue("production_mode", "true");
                    // [optional]Set extra fields
                    //$brocast->setExtraField("test", "helloworld");
                    $this->_log("Sending broadcast notification, please wait...\r\n");
                    $brocast->send();
                    $this->_log("Sent SUCCESS\r\n");
            } catch (Exception $e) {
                    $this->_log("Caught exception: " . $e->getMessage());
            }
        }
        
	/**
	 * The process main loop.
	 *
	 * During the main loop: the per-process error queue is read and the common error message
	 * container is populated; the per-process message queue is spooled (message from
	 * this queue is added to UmengPHP_Push queue and delivered).
	 */
	protected function _mainLoop()
	{
		while (true) {
			pcntl_signal_dispatch();

			if (posix_getppid() != $this->_nParentPid) {
				$this->_log("INFO: Parent process {$this->_nParentPid} died unexpectedly, exiting...");
				break;
			}

			sem_acquire($this->_hSem);
			$this->_setQueue(self::SHM_ERROR_MESSAGES_QUEUE_KEY, 0,
				array_merge($this->_getQueue(self::SHM_ERROR_MESSAGES_QUEUE_KEY), array())
			);

			$aQueue = $this->_getQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $this->_nCurrentProcess);
			foreach($aQueue as $message) {
                            //发送
                            $this->_pushAndroidMessage($message);
			}
                        
			$this->_setQueue(self::SHM_MESSAGES_QUEUE_KEY_START, $this->_nCurrentProcess);
			sem_release($this->_hSem);

			$nMessages = count($aQueue);
			if ($nMessages > 0) {
                            $this->_log('INFO: Process ' . ($this->_nCurrentProcess + 1) . " has {$nMessages} messages, sending...");
			} else {
                            usleep(self::MAIN_LOOP_USLEEP);
			}
		}
	}

	/**
	 * Returns the queue from the shared memory.
	 *
	 * @param  $nQueueKey @type integer The key of the queue stored in the shared
	 *         memory.
	 * @param  $nProcess @type integer @optional The process cardinal number.
	 * @return @type array Array of messages from the queue.
	 */
	protected function _getQueue($nQueueKey, $nProcess = 0)
	{
		if (!shm_has_var($this->_hShm, $nQueueKey + $nProcess)) {
			return array();
		}
		return shm_get_var($this->_hShm, $nQueueKey + $nProcess);
	}

	/**
	 * Store the queue into the shared memory.
	 *
	 * @param  $nQueueKey @type integer The key of the queue to store in the shared
	 *         memory.
	 * @param  $nProcess @type integer @optional The process cardinal number.
	 * @param  $aQueue @type array @optional The queue to store into shared memory.
	 *         The default value is an empty array, useful to empty the queue.
	 * @return @type boolean True on success, false otherwise.
	 */
	protected function _setQueue($nQueueKey, $nProcess = 0, $aQueue = array())
	{
		if (!is_array($aQueue)) {
			$aQueue = array();
		}
		return shm_put_var($this->_hShm, $nQueueKey + $nProcess, $aQueue);
	}
}







// timezone
date_default_timezone_set('PRC');
// Report all PHP errors
error_reporting(E_ALL ^ E_NOTICE);
// load Redis
require '../redis/Predis.php';
//Predis\Autoloader::register();
// connect to Redis server
$redis = new Predis_Client(array('host'=>'127.0.0.1','port'=>6379));
// Redis queue key
define ("QUEUE_KEY",'list.umeng.messagequeue');
// enable log
define ("_ENABLE_LOG",true);
// path to log
define ("LOGPATH",'logs/');

// Instanciate a new ApnsPHP_Push object
$server = new UmengPHP_Push_Server("你的appkey","你的app secret");

// Set the number of concurrent processes
$server->setProcesses(2);
// Starts the server forking the new processes
$server->start();
_pushLog(array(date('Y-m-d H:i:s'), 'STARTING SERVER'));
/*
 * Main server run loop
 */
while ($server->run()) {
	$date = date('Y-m-d H:i:s');
	// Check the error queue
	$aErrorQueue = $server->getErrors();
	if (!empty($aErrorQueue)) {
		var_dump($aErrorQueue);
	}
	// get latest queue
	list ($deviceToken, $text) = popQueue($redis);
	// push message if it has correct values
	if ($deviceToken && $text) {
		// Instantiate a new Message with a single recipient
		$message = new UmengPHP_Message();
		$message->setText(urldecode($text));
                $message->setDeviceToken($deviceToken);
		// Add the message to the message queue
		$server->add($message);
		_pushLog(array(date('Y-m-d H:i:s'), $deviceToken, $badgeNum));
		// continue to next loop for effectiveness
		continue;
	}
	usleep(500000);
        _pushLog(array("next loop"));
}
/*
 * Pop from queue.
 * Currently using redis
 */
function popQueue ($redis) {
	$queueRow = $redis->lpop(QUEUE_KEY);
        if ($queueRow){
            return explode(':', $queueRow);
        }
        return null;
}
/*
 * add to log.
 */
function _pushLog ($args) {
    if (!_ENABLE_LOG) {
            return;
    }
    $fileFullPath = LOGPATH .date("Y-m-d")."_push.log";
    $logMessage = implode("\t", $args);
    //echo $fileFullPath."\n";
    if ($FH = fopen($fileFullPath, 'a')) {
            if (!fputs($FH, $logMessage."\n")) {
                    echo "failed to put logfile\n";
            }
            fclose($FH);
    } else {
            echo "failed to open logfile\n";
    }
}
