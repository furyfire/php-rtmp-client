<?php

namespace RTMP;

class Stream
{

    private $index = 0;
    private $data;

    /**
     * Construct a new data stream with optional data.
     * 
     * @param string $data Binary stream data
     */
    public function __construct($data = "")
    {
        $this->data = $data;
    }

    public function reset()
    {
        $this->index = 0;
    }

    public function flush($length = -1)
    {
        if ($length == -1) {
            $data = $this->data;
            $this->data = "";
        } else {
            $data = substr($this->data, 0, $length);
            $this->data = substr($this->data, $length);
        }
        $this->index = 0;
        return $data;
    }

    /**
     * Dump the entire data stream
     * 
     * @return string Binary data
     */
    public function dump()
    {
        return $this->data;
    }

    /**
     * Return the index to the starting point
     * 
     * @return \RTMP\Stream
     */
    public function begin()
    {
        $this->index = 0;
        return $this;
    }

    public function move($pos)
    {
        $this->index = max(array(0, min(array($pos, strlen($this->data)))));
        return $this;
    }

    public function end()
    {
        $this->index = strlen($this->data);
        return $this;
    }

    public function push($data)
    {
        $this->data .= $data;
        return $this;
    }

    //--------------------------------
    //		Writer
    //--------------------------------

    /**
     * Write a byte to the binary stream
     * @param int $value Single byte
     */
    public function writeByte($value)
    {
        $this->data .= is_int($value) ? chr($value) : $value;
        $this->index++;
    }

    /**
     * Write an Int16 to the binary stream
     * @param int $value Int16
     */
    public function writeInt16($value)
    {
        $this->data .= pack("s", $value);
        $this->index += 2;
    }

    /**
     * Write an Int24 to the binary stream
     * @param int $value Int24
     */
    public function writeInt24($value)
    {
        $this->data .= substr(pack("N", $value), 1);
        $this->index += 3;
    }

    /**
     * Write an Int32 to the binary stream
     * @param int $value Int32
     */
    public function writeInt32($value)
    {
        $this->data .= pack("N", $value);
        $this->index += 4;
    }

    /**
     * Write a Uint32 to the binary stream
     * @param int $value Uint32
     */
    public function writeInt32LE($value)
    {
        $this->data .= pack("V", $value);
        $this->index += 4;
    }

    /**
     * Write binary data to the binary stream
     * @param type $value Binary data
     */
    public function write($value)
    {
        $this->data .= $value;
        $this->index += strlen($value);
    }

    //-------------------------------
    //		Reader
    //-------------------------------
    
    /**
     * Read a byte from the binary stream
     * @return int Char
     */
    public function readByte()
    {
        return ($this->data[$this->index++]);
    }

    /**
     * Read a Uint8 from the binary stream
     * @return int Uint8
     */
    public function readTinyInt()
    {
        return ord($this->readByte());
    }

    /**
     * Read an Int16 from the binary stream
     * @return int Int16
     */
    public function readInt16()
    {
        return $this->read("s", 2);
    }

    /**
     * Read an int24 from the binary stream
     * @return int Int24
     */
    public function readInt24()
    {
        $val = unpack("N", "\x00" . substr($this->data, $this->index, 3));
        $this->index += 3;
        return $val[1];
    }

    /**
     * Read an Int32 from the binary stream
     * @return int Int32
     */
    public function readInt32()
    {
        return $this->read("N", 4);
    }

    /**
     * Read a uint32 from the binary stream
     * @return int uint32
     */
    public function readInt32LE()
    {
        return $this->read("V", 4);
    }

    /**
     * Read binary from the stream
     * @param type $length Read length
     * @return string Binary
     */
    public function readRaw($length = 0)
    {
        if ($length == 0) {
            $length = strlen($this->data) - $this->index;
        }

        $datas = substr($this->data, $this->index, $length);
        $this->index += $length;
        return $datas;
    }

    private function read($type, $size)
    {
        $val = unpack("$type", substr($this->data, $this->index, $size));
        $this->index += $size;
        return $val[1];
    }
}
