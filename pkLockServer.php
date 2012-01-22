<?php

class pkMessageConnection
{
  public $socket;
  public $outputBuffer;
  public $inputBuffer;
  public $messagesReceived;
  public $connecting = false;
  public $closed = false;
  
  public function __construct($socket)
  {
    $this->socket = $socket;
  }
  
  public function receiveFromSocket()
  {
    $result = fread($this->socket, 65536);
    if ($result === false)
    {
      $this->closed = true;
      return;
    }
    $this->inputBuffer .= $result;
    do
    {
      $message = null;
      if (strlen($this->inputBuffer) >= 4)
      {
        $length = unpack("N", $this->inputBuffer);
        if (strlen($this->inputBuffer) - 4 >= $length)
        {
          $message = @json_decode(substr($this->inputBuffer, 4, $length));
          $this->inputBuffer = substr($this->inputBuffer, 4 + $length);
          $this->messagesReceived[] = $message;
        }
      }
    } while (isset($message));
  }

  public function writeToSocket()
  {
    if (strlen($this->outputBuffer))
    {
      $result = fwrite($this->socket, $this->outputBuffer);
      if ($result === false)
      {
        $this->closed = true;
        return;
      }
      $this->outputBuffer = substr($this->outputBuffer, $result);
    }
  }
  
  public function writeMessage($message)
  {
    $json = json_encode($message);
    $this->outputBuffer .= pack("N", strlen($json));
    $this->outputBuffer .= $json;
  }
}

class pkMessageServer
{
  protected $connections = array();
  protected $listener;
  
  public function __construct($settings)
  {
    $this->listener = stream_socket_server("tcp://{$settings['host']}:{$settings['port']}", $errno, $errstr);
    if (!$this->listener)
    {
      throw Exception("$errstr ($errno)");
    }
  }
  
  public function run()
  {
    while (true)
    {
      $this->poll();
    }
  }

  /**
   * Wait until there is activity on a socket, including the
   * listening socket, and respond appropriately. Wait efficiently
   * with a blocking select(), but don't wait more
   * than 1 second per call (this allows for non-socket-related 
   * activities in subclasses)
   */
  public function poll()
  {
    $read = array($this->listener);
    foreach ($this->connections as $connection)
    {
      $read[] = $connection->socket;
    }
    $write = array();
    foreach ($this->connections as $connection)
    {
      if (strlen($connection->outputBuffer))
      {
        $write[] = $connection->socket;
      }
    }
    $exception = array();
    $modified = stream_select($read, $write, $exception, 1.0);
    if (in_array($this->listener, $read))
    {
      $this->accept();
    }
    foreach ($read as $socket)
    {
      if ($socket !== $this->listener)
      {
        foreach ($this->connections as $connection)
        {
          if ($connection->socket === $socket)
          {
            $connection->receiveFromSocket();
          }
        }
      }
    }
    foreach ($write as $socket)
    {
      foreach ($this->connections as $connection)
      {
        if ($connection->socket === $socket)
        {
          $connection->writeToSocket();
        }
      }
    }
  }
  
  /**
   * Accept new socket which is ready according to select, create a connection
   */
  public function accept()
  {
    $socket = stream_socket_accept($this->listener, 0);
    if ($socket)
    {
      $connection = new pkMessageConnection($socket);
      $this->connections[] = $connection;
    }
  }  
  
  public function validateRpc($message)
  {
    return isset($message['id']) && isset($message['command']) && isset($message['parameters']);
  }
}

class pkMessageClient
{
  protected $connection;
  protected $rpcId = 0;
  
  public function __construct($settings)
  {
    $socket = stream_socket_client("tcp://{$settings['host']}:{$settings['port']}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
    $this->connection = new pkMessageConnection($socket);
    $this->connection->connecting = true;
  }
  
  public function poll()
  {
    $read = array($this->connection->socket);
    $write = array($this->connection->socket);
    $exception = array();
    $modified = stream_select($read, $write, $exception, 1.0);
    if (count($read))
    {
      if ($this->connection->connecting)
      {
        $this->connection->connecting = false;
      }
      else
      {
        $this->connection->receiveFromSocket();
      }
    }
    if (count($write))
    {
      $this->connection->writeToSocket();
    }
  }
  
  /**
   * Sometimes you just want an RPC call. Sends the specified command
   * (a string) and parameters (any JSON-friendly data) to the server
   * and waits for a response. Makes sure that response is to the
   * same request. Returns the 'result' key of the response.
   *
   * Servers wishing to be on the other end should expect to 
   * receive a message like this:
   *
   * array('command' => 'lock', 'parameters' => ... something ..., 'id' => 1)
   *
   * And should respond with a message like this:
   *
   * array('id' => 1, 'result' => array('status' => 'ok', ... )
   *
   * The 'status' key is a common convention not enforced by this method.
   * 
   * If $options['timeLimit'] is not set, the time limit is 30 seconds,
   * after which null is returned. If $options['timeLimit'] is explicitly false,
   * waits indefinitely. If the connection is broken, null is returned.
   */
   
  public function rpc($command, $parameters, $options)
  {
    $id = $this->rpcId;
    $message = array('command' => $command, 'parameters' => $parameters, 'id' => $this->rpcId);
    $this->rpcId++;
    $this->connection->writeMessage($message);
    $timeLimit = isset($options['timeLimit']) ? $options['timeLimit'] : 30;    
    do
    {
      $this->poll();
      if (count($this->messagesReceived))
      {
        $message = array_shift($this->messagesReceived);
        if (isset($message['id']) && ($message['id'] === $id) && isset($message['result']))
        {
          return $message['result'];
        }
      }
      $now = time();
      if ($this->connection->closed)
      {
        break;
      }
      if ($timeLimit !== false)
      {
        if ($now - $start < $timeLimit)
        {
          break;
        }
      }
    }
    return null;
  }
}

class pkLockServer extends pkMessageServer
{
  protected $locks = array();
  
  public function poll()
  {
    parent::poll();
    foreach ($this->connections as $connection)
    {
      while (count($connection->messagesReceived))
      {
        $message = array_shift($connection->messagesReceived);
        if (isset($message['command']))
        {
          $command = $messages['command'];
          $method = 'command' . ucfirst($command);
          if (method_exists($this, $method))
          {
            $this->$method($connection, $message);
          }
        }
      }
      if ($connection->closed)
      {
        // If someone hangs up, make sure they lose their locks.
        // This is the big advantage of a socket connection for locks
        $abandoned = array();
        foreach ($this->locks as $lockName => $lockConnection)
        {
          if ($connection === $lockConnection)
          {
            $abandoned[] = $lockName;
          }
        }
        foreach ($abandoned as $lockName)
        {
          unset($this->locks[$lockName]);
        }
      }
    }
  }
  
  public function commandLock($connection, $message)
  {
    // Make sure it's a reasonable RPC request
    if (!$this->validateRpc($message))
    {
      return;
    }
    
    $parameters = $message['parameters'];

    if (isset($parameters['lockName']))
    {
      $lockName = $parameters['lockName'];
      $this->locks[$lockName][] = array('message' => $message, 'connection' => $connection);
      $first = reset($this->locks[$lockName]);
      if ($connection === $first)
      {
        $this->rpcRespond($connection, $message, array('status' => 'ok'));
      }
    }
    else
    {
      $this->rpcRespond($connection, $message, array('status' => 'invalid'));
    }
  }

  public function commandUnlock($connection, $message)
  {
    if (isset($message['lockName']))
    {
      $lockName = $message['lockName'];
      $found = false;
      $firstFound = false;
      if (isset($this->locks[$lockName])
      {
        // Find this connection in the queue, if it's there,
        // and remove it. If it's the first respond to the RPC
        // request of the next in the queue
        $queue = array();
        $first = true;
        foreach ($this->locks[$lockName] as $request)
        {
          if ($connection === $request['connection'])
          {
            $found = true;
            if ($first)
            {
              $firstFound = true;
            }
          }
          else
          {
            $queue[] = $request;
          }
          $first = false;
        }
        $this->locks[$lockName] = $queue;
      }
      if ($found)
      {
        $message = array('attempted' => 'unlock', 'result' => 'ok', 'lockName' => $lockName);
        $connection->writeMessage($message);
        if ($firstFound === true)
        {
          if (count($this->locks[$lockName]))
          {
            $winner = reset($this->locks[$lockName]);
            $this->rpcRespond($winner['message'], array('status' => 'ok'));
          }
        }
      }
      else
      {
        $message = array('attempted' => 'unlock', 'result' => 'notFound', 'lockName' => $lockName);
        $connection->writeMessage($message);
      }
    }
    else
    {
      $message = array('attempted' => 'unlock', 'result' => 'invalid');
      $connection->writeMessage($message);
    }
  }
}
 
class pkLockClient extends pkMessageClient
{
  /**
   * Request a lock on the named resource. If it takes more than
   * $options['timeLimit'] seconds to get a lock, give up (default 30 seconds).
   * Specify $options['timeLimit'] => false to wait indefinitely
   */
  public function lock($lockName, $options = array())
  {
    $timeLimit = isset($options['timeLimit']) ? $options['timeLimit'] : 30;
    
    $result = $this->rpc('lockService.lock', array('lockName' => $lockName), array('timeLimit' => $timeLimit));
    
    if (is_null($result))
    {
      if (!$this->connection->closed)
      {
        // There's a chance the other end gave us a lock just as we gave up on them.
        // Just in case, issue a command to give up that lock if we have it.
        $this->writeMessage(array('command' => 'lockService.unlock', 'lockName' => $lockName));
      }
      return false;
    }
    
    if (isset($result['status']))
    {
      if ($result['status'] === 'ok')
      {
        return true;
      }
    }
    
    return false;
  }
  
  public function unlock($lockName)
  {
    $this->writeMessage(array('command' => 'lockService.unlock', 'lockName' => $lockName));
  }
}

