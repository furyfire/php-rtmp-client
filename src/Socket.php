<?php

namespace RTMP;

class Socket
{

    private $host;
    private $port;

    /**
     * @var resource stream socket connection
     */
    private $socket;
    public $timeout = 15;

    public function __construct()
    {
        
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function isOpen()
    {
        return !stream_get_meta_data($this->socket)['eof'];
    }

    /**
     * Init socket
     *
     * @return bool
     */
    public function connect($host, $port)
    {
        $this->close();
        $this->host = $host;
        $this->port = $port;


        $addr = gethostbyname($host);

        $this->socket = @stream_socket_client("tcp://" . $addr . ":" . $port, $errno, $errorMessage);

        if ($this->socket === false) {
            throw new \Exception("Failed to connect: [$errno] $errorMessage");
        }
        return true;


        if (($this->socket = socket_create(AF_INET, SOCK_STREAM, 0)) == false) {
            throw new \Exception("Unable to create socket.");
        }

        if (!socket_connect($this->socket, $this->host, $this->port)) {
            throw new \Exception("Could not connect to $this->host:$this->port");
        }

        return $this->socket != null;
    }

    /**
     * Close socket
     *
     */
    public function close()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * Read socket
     *
     * @param int $length
     * @return RtmpStream
     */
    public function read($length)
    {
        $buff = "";
        $time = time();
        do {
            $recv = "";
            $recv = fread($this->socket, $length - strlen($buff));
            //$recv = socket_read($this->socket, $length - strlen($buff), PHP_BINARY_READ);
            if ($recv === false) {
                throw new Exception("Could not read socket");
            }

            if ($recv != "") {
                $buff .= $recv;
            }

            if (time() > $time + $this->timeout) {
                throw new Exception("Timeout, could not read socket");
            }
        } while ($recv != "" && strlen($buff) < $length);
        $this->recvBuffer = substr($buff, $length);
        return new Stream(substr($buff, 0, $length));
    }

    /**
     * Write data
     *
     * @param RtmpStream $data
     * @param int $length Length to write
     * @return bool
     */
    public function write(Stream $data, $length = -1)
    {
        $buffer = $data->flush($length);
        $length = strlen($buffer);
        while ($length > 0) {
            $nBytes = fwrite($this->socket, $buffer, $length);
            //$nBytes = socket_write($this->socket, $buffer, $n);
            if ($nBytes === false) {
                $this->close();
                return false;
            }

            if ($nBytes == 0) {
                break;
            }

            $length -= $nBytes;
            $buffer = substr($buffer, $nBytes);
        }
        return true;
    }

}
