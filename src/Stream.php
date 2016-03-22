<?php

namespace RTMP;

class Stream
{

    private $index = 0;
    private $data;

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

    public function dump()
    {
        return $this->data;
    }

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

    public function writeByte($value)
    {
        $this->data .= is_int($value) ? chr($value) : $value;
        $this->index++;
    }

    public function writeInt16($value)
    {
        $this->data .= pack("s", $value);
        $this->index += 2;
    }

    public function writeInt24($value)
    {
        $this->data .= substr(pack("N", $value), 1);
        $this->index += 3;
    }

    public function writeInt32($value)
    {
        $this->data .= pack("N", $value);
        $this->index += 4;
    }

    public function writeInt32LE($value)
    {
        $this->data .= pack("V", $value);
        $this->index += 4;
    }

    public function write($value)
    {
        $this->data .= $value;
        $this->index += strlen($value);
    }

    //-------------------------------
    //		Reader
    //-------------------------------

    public function readByte()
    {
        return ($this->data[$this->index++]);
    }

    public function readTinyInt()
    {
        return ord($this->readByte());
    }

    public function readInt16()
    {
        return $this->read("s", 2);
    }

    public function readInt24()
    {
        $val = unpack("N", "\x00" . substr($this->data, $this->index, 3));
        $this->index += 3;
        return $val[1];
    }

    public function readInt32()
    {
        return $this->read("N", 4);
    }

    public function readInt32LE()
    {
        return $this->read("V", 4);
    }

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
