<?php

namespace RTMP;

class Socket
{

    /**
     * @var resource stream socket connection
     */
    private $socket;
    
    /**
     * Timeout in seconds for socket reads
     * @var int  
     */
    public $timeout = 15;

    /**
     * Get the current Stream Network socket
     * 
     * @return resource Stream Socket connection
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Get the current status of the network socket
     * 
     * @return bool True if socket is open
     */
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

        $addr = gethostbyname($host);
        if ($addr === $host) {
            throw new \Exception("Could not resolve: $host");
        }

        $this->socket = @stream_socket_client("tcp://" . $addr . ":" . $port, $errno, $errorMessage);

        if ($this->socket === false) {
            throw new \Exception("Failed to connect: [$errno] $errorMessage");
        }
        return true;
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
     * @return Stream
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
                throw new \Exception("Could not read socket");
            }

            if ($recv != "") {
                $buff .= $recv;
            }

            if (time() > $time + $this->timeout) {
                throw new \Exception("Timeout, could not read socket");
            }
        } while ($recv != "" && strlen($buff) < $length);
        $this->recvBuffer = substr($buff, $length);
        return new Stream(substr($buff, 0, $length));
    }

    /**
     * Write data
     *
     * @param Stream $data
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
