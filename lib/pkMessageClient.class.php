<?php

class pkMessageClient
{
  protected $connection;
  protected $rpcId = 0;
  
  public function __construct($settings)
  {
    $stream = stream_socket_client("tcp://{$settings['host']}:{$settings['port']}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
    stream_set_blocking($stream, 0);
    $this->connection = new pkMessageConnection($stream);
    $this->connection->connecting = true;
  }
  
  public function poll()
  {
    $read = array($this->connection->stream);
    $write = array($this->connection->stream);
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
        $this->connection->receiveFromStream();
      }
    }
    if (count($write))
    {
      $this->connection->writeToStream();
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
    $start = time();
    while (true)
    {
      $this->poll();
      if (count($this->connection->messagesReceived))
      {
        $message = array_shift($this->connection->messagesReceived);
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
        if ($now - $start > $timeLimit)
        {
          break;
        }
      }
    }
    return null;
  }

  /**
   * Send whatever data remains in the output buffer. Keep trying for
   * 30 seconds, or $options['timeLimit'] if specified. A timeLimit of false
   * keeps trying until the stream is closed. TODO: refactor the
   * time limit poll code that keeps getting duplicated
   */
   
  public function flush($options)
  {
    $timeLimit = isset($options['timeLimit']) ? $options['timeLimit'] : 30;
    $start = time();
    while (strlen($this->connection->outputBuffer))
    {
      $this->poll();
      if (!strlen($this->connection->outputBuffer))
      {
        // All good, it's sent, don't flag it as an error if the connection is now closed
        return true;
      }
      if ($this->connection->closed)
      {
        return false;
        break;
      }
      if ($timeLimit !== false)
      {
        if ($now - $start > $timeLimit)
        {
          break;
        }
      }
    }
    return false;
  }
}

