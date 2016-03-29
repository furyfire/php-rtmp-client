<?php

namespace RTMP;

class Message
{

    const AMF0 = 0;
    const AMF3 = 3;

    private static $currentTransactionID = 0;
    public $commandName;
    public $transactionId;
    public $commandObject;
    public $arguments;
    private $packet;

    public function __construct($commandName = "", $commandObject = null, $arguments = null)
    {
        $this->commandName = $commandName;
        $this->commandObject = $commandObject;
        $this->arguments = $arguments;
    }

    /**
     * getPacket
     *
     * @return \RTMP\Packet
     */
    public function getPacket()
    {
        return $this->packet;
    }

    public function setPacket($packet)
    {
        $this->packet = $packet;
    }

    public function getData()
    {
        if ($this->arguments instanceof \SabreAMF\AMF3\Wrapper) {
            return $this->arguments->getData();
        } else {
            return $this->arguments;
        }
    }
    /**
     * Encode Message
     *
     * @return RtmpPacket
     */
    public function encode()
    {
        $amfVersion = Client::AMF_VERSION; //Using AMF3
        //Increment transaction id
        $this->transactionId = self::$currentTransactionID++;

        //Create packet
        $packet = new Packet();
        if ($this->commandName == "connect") {
            $this->transactionId = 1;
            $amfVersion = Message::AMF0; //Connect packet must be in AMF0
        }
        $packet->chunkStreamId = 3;
        $packet->streamId = 0;
        $packet->chunkType = Packet::CHUNK_TYPE_0;
        $packet->type = $amfVersion == Message::AMF0 ? Packet::TYPE_INVOKE_AMF0 : Packet::TYPE_INVOKE_AMF3; //Invoke
        //Encoding payload
        $stream = new \SabreAMF\OutputStream();
        $serializer = new \SabreAMF\AMF0\Serializer($stream);
        $serializer->writeAMFData($this->commandName);
        $serializer->writeAMFData($this->transactionId);
        $serializer->writeAMFData($this->commandObject);

        if ($this->arguments != null) {
            foreach ($this->arguments as $arg) {
                $serializer->writeAMFData($arg);
            }
        }

        $packet->payload = '';

        if ($amfVersion == Message::AMF3) {
            $packet->payload = "\x00"; //XXX: put empty bytes in amf3 mode...I don't know why..*/
        }

        $packet->payload .= $stream->getRawData();

        $this->packet = $packet;

        return $packet;
    }

    public function decode(Packet $packet)
    {
        $this->packet = $packet;
        $amfVersion = $packet->type == Packet::TYPE_INVOKE_AMF0 ? Message::AMF0 : Message::AMF3;
        if ($amfVersion == 3 && $packet->payload{0} == chr(0)) {
            $packet->payload = substr($packet->payload, 1);
            $amfVersion = Message::AMF0;
        }

        $stream = new \SabreAMF\InputStream($packet->payload);
        if ($amfVersion == Message::AMF0) {
            $deserializer = new \SabreAMF\AMF0\Deserializer($stream);
        } else {
            $deserializer = new \SabreAMF\AMF3\Deserializer($stream);
        }
           
        $this->commandName = $deserializer->readAMFData();
        $this->transactionId = $deserializer->readAMFData();
        $this->commandObject = $deserializer->readAMFData();
        try {
            $this->arguments = $deserializer->readAMFData();
        } catch (\Exception $e) {
            //if not exists InputStream throw exeception
            $this->arguments = null;
        }
    }

    public function isError()
    {
        if (($this->commandName == "_error") || (is_array($this->arguments) &&
                !empty($this->arguments) && isset($this->arguments['level']) &&
                ($this->arguments['level'] == 'error'))) {
            return true;
        }
        return false;
    }

    /**
     * Return if message is a response message
     *
     * @return bool
     */
    public function isResponseCommand()
    {
        return $this->commandName == "_result" || $this->commandName == "_error";
    }
}
