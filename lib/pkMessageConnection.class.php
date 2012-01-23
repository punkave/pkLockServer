<?php

class pkMessageConnection
{
  public $stream;
  public $outputBuffer;
  public $inputBuffer;
  public $messagesReceived = array();
  public $connecting = false;
  public $closed = false;
  
  public function __construct($stream)
  {
    $this->stream = $stream;
  }
  
  public function receiveFromStream()
  {
    if (feof($this->stream))
    {
      $this->closed = true;
      return;
    }
    $result = @fread($this->stream, 65536);
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
        $result = unpack("Nlength", $this->inputBuffer);
        $length = $result['length'];
        if (strlen($this->inputBuffer) - 4 >= $length)
        {
          $message = @json_decode(substr($this->inputBuffer, 4, $length), true);
          $this->inputBuffer = substr($this->inputBuffer, 4 + $length);
          $this->messagesReceived[] = $message;
        }
      }
    } while (isset($message));
  }

  public function writeToStream()
  {
    if (feof($this->stream))
    {
      $this->closed = true;
      return;
    }
    if (strlen($this->outputBuffer))
    {
      $result = @fwrite($this->stream, $this->outputBuffer);
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
