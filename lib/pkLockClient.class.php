<?php

require dirname(__FILE__) . '/pkMessageConnection.class.php';
require dirname(__FILE__) . '/pkMessageClient.class.php';

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
    
    $result = $this->rpc('lock', array('lockName' => $lockName), array('timeLimit' => $timeLimit));
    
    if (is_null($result))
    {
      if (!$this->connection->closed)
      {
        // There's a chance the other end gave us a lock just as we gave up on them.
        // Just in case, issue a command to give up that lock if we have it.
        $this->connection->writeMessage(array('command' => 'unlock', 'lockName' => $lockName));
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
  
  /**
   * Release the specified lock. Returns true if the request is successfully
   * transmitted (not necessarily delivered - however if the connection fails our lock is 
   * discarded in any case). Keeps trying for 30 seconds unless $options['timeLimit'] is specified.
   * Setting $options['timeLimit'] to false keeps trying until the connection
   * is closed
   */
  public function unlock($lockName, $options = array())
  {
    $this->connection->writeMessage(array('command' => 'unlock', 'lockName' => $lockName));
    return $this->flush($options);
  }
}

