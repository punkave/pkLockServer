<?php

require dirname(__FILE__) . '/pkMessageConnection.class.php';
require dirname(__FILE__) . '/pkMessageServer.class.php';

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
          $command = $message['command'];
          $method = 'command' . ucfirst($command);
          if (method_exists($this, $method))
          {
            $this->$method($connection, $message);
          }
        }
      }
    }
  }
  
  public function reaped($connection)
  {
    // If someone hangs up, make sure they lose their locks.
    // This is the big advantage of a socket connection for locks
    foreach ($this->locks as $lockName => $requestQueue)
    {
      foreach ($requestQueue as $request)
      {
        if ($request['connection'] === $connection)
        {
          $this->commandUnlock($connection, array('lockName' => $lockName));
        }
      }
    }
  }
  
  public function commandLock($connection, $message)
  {
    // Make sure it's a reasonable RPC request
    if (!$this->rpcValidate($message))
    {
      return;
    }
    
    $parameters = $message['parameters'];

    if (isset($parameters['lockName']))
    {
      $lockName = $parameters['lockName'];
      $this->locks[$lockName][] = array('message' => $message, 'connection' => $connection);
      $first = reset($this->locks[$lockName]);
      if ($connection === $first['connection'])
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
      if (isset($this->locks[$lockName]))
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
            $this->rpcRespond($winner['connection'], $winner['message'], array('status' => 'ok'));
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
 