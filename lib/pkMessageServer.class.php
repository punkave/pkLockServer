<?php

class pkMessageServer
{
  protected $connections = array();
  protected $listener;
  protected $allowed;
  
  public function __construct($settings)
  {
    if (isset($settings['allowed']))
    {
      $this->allowed = array();
      foreach ($settings['allowed'] as $ip)
      {
        $this->allowed[$ip] = true;
      }
    }
    $this->listener = stream_socket_server("tcp://{$settings['host']}:{$settings['port']}", $errno, $errstr);
    stream_set_blocking($this->listener, 0);
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
      $this->reap();
    }
  }

  /**
   * Wait until there is activity on a stream, including the
   * listening stream, and respond appropriately. Wait efficiently
   * with a blocking select(), but don't wait more
   * than 1 second per call (this allows for non-stream-related 
   * activities in subclasses)
   */
  public function poll()
  {
    $read = array($this->listener);
    foreach ($this->connections as $connection)
    {
      $read[] = $connection->stream;
    }
    $write = array();
    foreach ($this->connections as $connection)
    {
      if (strlen($connection->outputBuffer))
      {
        $write[] = $connection->stream;
      }
    }
    $exception = array();
    $modified = stream_select($read, $write, $exception, 1.0);
    if ($modified > 0)
    {
      if (in_array($this->listener, $read))
      {
        $this->accept();
      }
      foreach ($read as $stream)
      {
        if ($stream !== $this->listener)
        {
          foreach ($this->connections as $connection)
          {
            if ($connection->stream === $stream)
            {
              $connection->receiveFromStream();
            }
          }
        }
      }
      foreach ($write as $stream)
      {
        foreach ($this->connections as $connection)
        {
          if ($connection->stream === $stream)
          {
            $connection->writeToStream();
          }
        }
      }
    }
  }
  
  /**
   * Iterate over connections looking for closed connections and
   * build a new queue without them, after first calling reaped()
   * for each one to allow custom cleanup
   */
  public function reap()
  {
    $connections = array();
    foreach ($this->connections as $connection)
    {
      if ($connection->closed)
      {
        $this->reaped($connection);
        fclose($connection->stream);
      }
      else
      {
        $connections[] = $connection;
      }
    }
    $this->connections = $connections;
  }

  /**
   * Called when a connection has closed and is about
   * to be removed. This is your opportunity to do 
   * custom cleanup such as yielding locks in pkLockServer
   */
  public function reaped($connection)
  {
  }
  
  /**
   * Accept new stream which is ready according to select, create a connection
   */
  public function accept()
  {
    $stream = stream_socket_accept($this->listener, 0, $peerName);
    list($ip, $port) = preg_split('/:/', $peerName);
    if (isset($this->allowed))
    {
      if (!isset($this->allowed[$ip]))
      {
        fclose($stream);
        return;
      }
    }
    if ($stream)
    {
      $connection = new pkMessageConnection($stream);
      $this->connections[] = $connection;
    }
  }  

  /**
   * Validate that the message is a well-formed RPC request
   */
  public function rpcValidate($message)
  {
    return isset($message['id']) && isset($message['command']) && isset($message['parameters']);
  }
  
  public function rpcRespond($connection, $originalMessage, $result)
  {
    $connection->writeMessage(array('id' => $originalMessage['id'], 'result' => $result));
  }
}
