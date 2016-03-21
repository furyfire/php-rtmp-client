<?php

namespace RTMP;

/**
 * RTMPClient
 * 
 * The client connection class.
 * Implements the PSR logging standard
 */
class Client implements \Psr\Log\LoggerAwareInterface
{

    use \Psr\Log\LoggerAwareTrait;

    const RTMP_SIG_SIZE = 1536;
    const AMF_VERSION = Message::AMF3;

    /**
     * Socket object
     *
     * @var Socket
     */
    private $socket;
    private $host;
    private $application;
    private $port;
    private $chunkSizeR = 128;
    private $chunkSizeW = 128;
    private $operations = array();

    /**
     * @var bool Connection status
     */
    private $connected = false;

    /**
     * @var ClientInterface Client
     */
    private $client;

    /**
     * Default connection settings for RtmpClient
     * The app and tcUrl field are automatically generated before sending
     * connect package.
     * You can overwrite entries in connect() call.
     * 
     * @var array $default_settings
     */
    private $DefaultConnectSettings = array(
        "flashVer" => "LNX 10,0,32,18",
        "swfUrl" => null,
        "fpad" => false,
        "capabilities" => 0.0,
        "audioCodecs" => 0x01,
        "videoCodecs" => 0xFF,
        "videoFunction" => 1,
        "pageUrl" => null,
        "objectEncoding" => 0x03
    );

    /**
     * Set the client
     * 
     * All remote invokes from the server will be forwarded to this class.
     * It is highly suggested to implement a __call() to notify about unhandled 
     * events in your client implementation.
     * 
     * @param ClientInterface $client Client object 
     */
    public function setClient(ClientInterface $client)
    {
        if (is_object($client) || is_null($client)) {
            $this->client = $client;
        }
    }
    
    /**
     * Connection status
     * 
     * @return bool True if connected
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * Connect
     *
     * Connects to a given RTMP server supplying the hostname, application path
     * and port number. 
     * The default connect settings are overwriteable by setting individual array
     * keys.
     * 
     * @param string $host Remote host or IP address
     * @param string $application RTMP application path
     * @param int $port Port the RMTP server is running on
     * @param array $connect_settings Connection parameter overwrite
     * @param mixed $connectParams Application specific connect parameters
     */
    public function connect($host, $application, $port = 1935, $connectSettings = null, $connectParams = null)
    {
        $this->close();

        $this->host = $host;
        $this->application = $application;
        $this->port = $port;

        if ($this->initSocket()) {
            $this->handshake();
            $this->sendConnectPacket($connectSettings, $connectParams);
        }
    }

    /**
     * Close connection
     *
     * Gracefully close the connect and restore chunksize.
     */
    public function close()
    {
        $this->socket && $this->socket->close();
        $this->chunkSizeR = $this->chunkSizeW = 128;
    }

    /**
     * Call remote procedure (RPC)
     *
     * Call a method on the RTMP server 
     * @param string $procedureName Method name
     * @param array $args Array of arguments, null if no arguments
     * @param callback $handler Callback class
     * @todo Add check so you do not overwrite a pending operation
     * @return mixed Result of RPC
     */
    public function call($procedureName, array $args = null, $handler = null)
    {
        return $this->sendOperation(new Operation(new Message($procedureName, null, $args), $handler));
    }

    /**
     * Magic wrapper forwarding to call()
     * 
     * @see call()
     */
    public function __call($name, $arguments)
    {
        return $this->call($name, $arguments);
    }

    //------------------------------------
    //		Socket
    //------------------------------------
    private function initSocket()
    {
        $this->socket = new Socket();
        return $this->socket->connect($this->host, $this->port);
    }

    private function socketRead($length)
    {
        return $this->socket->read($length);
    }

    private function socketWrite(Stream $data, $length = -1)
    {
        return $this->socket->write($data, $length);
    }

    /**
     * @internal
     * @var bool Already listening
     */
    private $listening = false;

    /**
     * Listen for incomming packages
     * 
     * Call this from a loop to maintain a persistant connection
     *
     * @return mixed Last result
     */
    public function listen()
    {
        //Prevents listening more than once
        if ($this->listening OR !$this->socket) {
            return;
        }
        $this->listening = true;
        $stop = false;
        $return = null;
        while (!$stop) {
            //Check that our socket is still open
            if (!$this->socket->isOpen()) {
                throw new \Exception("Socket no longer open");
            }
            if ($packet = $this->readPacket()) {
                switch ($packet->type) {
                    case Packet::TYPE_CHUNK_SIZE: //Chunk size
                        $this->handleSetChunkSize($packet);
                        break;
                    case Packet::TYPE_READ_REPORT: //Bytes Read
                        break;
                    case Packet::TYPE_PING: //Ping
                        $this->handlePing($packet);
                        break;
                    case Packet::TYPE_SERVER_BW: //Window Acknowledgement Size
                        $this->handleWindowAcknowledgementSize($packet);
                        break;
                    case Packet::TYPE_CLIENT_BW: //Peer BW
                        $this->handleSetPeerBandwidth($packet);
                        break;
                    case Packet::TYPE_AUDIO: //Audio Data

                        break;
                    case Packet::TYPE_VIDEO: //Video Data

                        break;
                    case Packet::TYPE_METADATA: //Notify

                        break;
                    case Packet::TYPE_INVOKE_AMF0: //No break on purpose
                    case Packet::TYPE_INVOKE_AMF3: //Invoke
                        $return = $this->handleInvoke($packet);
                        if (sizeof($this->operations) == 0) {
                            $stop = true;
                        }
                        break;
                    case Packet::TYPE_FLV_TAGS:

                        break;
                    case Packet::TYPE_AGGREGATE: //Agregate

                        break;
                    default:
                        $this->logger->warn("Unknown RTMP Packet Type", (array) $packet);
                        break;
                }
            }
            usleep(1);
        }
        $this->listening = false;
        return $return;
    }

    /**
     * Previous packet
     * @internal
     *
     * @var Packet
     */
    private $prevReadingPacket = array();

    /**
     * Read packet
     *
     * @return Packet
     */
    private function readPacket()
    {
        $packet = new Packet();

        $header = $this->socketRead(1)->readTinyInt();

        $packet->chunkType = (($header & 0xc0) >> 6);
        $packet->chunkStreamId = $header & 0x3f;

        switch ($packet->chunkStreamId) {
            case 0: //range of 64-319, second byte + 64
                $packet->chunkStreamId = 64 + $this->socketRead(1)->readTinyInt();
                break;
            case 1: //range of 64-65599,third byte * 256 + second byte + 64
                $packet->chunkStreamId = 64 + $this->socketRead(1)->readTinyInt() +
                    $this->socketRead(1)->readTinyInt() * 256;
                break;
            case 2:
                break;
            default: //range of 3-63
                // complete stream ids
        }
        switch ($packet->chunkType) {
            case Packet::CHUNK_TYPE_3:
                $packet->timestamp = $this->prevReadingPacket[$packet->chunkStreamId]->timestamp;
                //We drop through
            case Packet::CHUNK_TYPE_2:
                $packet->length = $this->prevReadingPacket[$packet->chunkStreamId]->length;
                $packet->type = $this->prevReadingPacket[$packet->chunkStreamId]->type;
                //We drop through
            case Packet::CHUNK_TYPE_1:
                $packet->streamId = $this->prevReadingPacket[$packet->chunkStreamId]->streamId;
                //We drop through
            case Packet::CHUNK_TYPE_0:
                break;
        }
        $this->prevReadingPacket[$packet->chunkStreamId] = $packet;
        $headerSize = Packet::$sizes[$packet->chunkType];

        if ($headerSize == Packet::MAX_HEADER_SIZE) {
            $packet->hasAbsTimestamp = true;
        }

        //If not operation exists, create it
        if (!isset($this->operations[$packet->chunkStreamId])) {
            $this->operations[$packet->chunkStreamId] = new RtmpOperation();
        }

        if ($this->operations[$packet->chunkStreamId]->getResponse()) {
            //Operation chunking....
            $packet = $this->operations[$packet->chunkStreamId]->getResponse()->getPacket();
            $headerSize = 0; //no header
        } else {
            //Create response from packet
            $this->operations[$packet->chunkStreamId]->createResponse($packet);
        }


        $headerSize--;
        $header = null;
        if ($headerSize > 0) {
            $header = $this->socketRead($headerSize);
        }

        if ($headerSize >= 3) {
            $packet->timestamp = $header->readInt24();
        }
        if ($headerSize >= 6) {
            $packet->length = $header->readInt24();

            $packet->bytesRead = 0;
            $packet->free();
        }
        if ($headerSize > 6) {
            $packet->type = $header->readTinyInt();
        }

        if ($headerSize == 11) {
            $packet->streamId = $header->readInt32LE();
        }


        $nToRead = $packet->length - $packet->bytesRead;
        $nChunk = $this->chunkSizeR;
        if ($nToRead < $nChunk) {
            $nChunk = $nToRead;
        }

        if ($packet->payload == null) {
            $packet->payload = "";
        }
        $packet->payload .= $this->socketRead($nChunk)->flush();
        if ($packet->bytesRead + $nChunk != strlen($packet->payload)) {
            throw new \Exception("Read failed. Read: " . strlen($packet->payload) ."/". ($packet->bytesRead + $nChunk));
        }
        $packet->bytesRead += $nChunk;

        if ($packet->isReady()) {
            return $packet;
        }

        return null;
    }

    /**
     * Previous packet
     * @internal
     *
     * @var Packet
     */
    private $prevSendingPacket = array();

    /**
     * Send packet
     *
     * @param Packet $packet
     * @throws \Exception On general error
     * @return bool True On success
     */
    private function sendPacket(Packet $packet)
    {
        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }
        if (isset($this->prevSendingPacket[$packet->chunkStreamId])) {
            if ($packet->length == $this->prevSendingPacket[$packet->chunkStreamId]->length) {
                $packet->chunkType = Packet::CHUNK_TYPE_2;
            } else {
                $packet->chunkType = Packet::CHUNK_TYPE_1;
            }
        }
        if ($packet->chunkType > 3) { //sanity
            throw new \Exception("sanity failed!! tring to send header of type: 0x%02x");
        }

        $this->prevSendingPacket[$packet->chunkStreamId] = $packet;

        $headerSize = Packet::$sizes[$packet->chunkType];
        //Initialize header
        $header = new Stream();
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        if ($headerSize > 1) {
            $packet->timestamp = time();
            $header->writeInt24($packet->timestamp);
        }

        if ($headerSize > 4) {
            $header->writeInt24($packet->length);
            $header->writeByte($packet->type);
        }
        if ($headerSize > 8) {
            $header->writeInt32LE($packet->streamId);
        }

        // Send header
        $this->socketWrite($header);

        $headerSize = $packet->length;
        $buffer = new Stream($packet->payload);

        //Push out while not going above chunkSize
        while ($headerSize) {
            if ($packet->type == Packet::TYPE_INVOKE_AMF0 || $packet->type == Packet::TYPE_INVOKE_AMF3) {
                $chunkSize = $this->chunkSizeW;
            } else {
                $chunkSize = $packet->length;
            }
            if ($headerSize < $this->chunkSizeW) {
                $chunkSize = $headerSize;
            }

            if (!$this->socketWrite($buffer, $chunkSize)) {
                throw new \Exception("Socket write error (write : $chunkSize)");
            }

            $headerSize -= $chunkSize;
            //$buffer = substr($buffer,$chunkSize);

            if ($headerSize > 0) {
                $sep = (0xc0 | $packet->chunkStreamId);
                if (!$this->socketWrite(new Stream(chr($sep)), 1)) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function sendOperation(Operation $operation)
    {
        $this->operations[$operation->getChunkStreamID()] = $operation;
        $this->sendPacket($operation->getCall()->getPacket());
        return $this->listen();
    }

    //------------------------------------
    //		RTMP Methods
    //------------------------------------
    /**
     * Perform handshake intial handshake
     *
     */
    private function handshake()
    {
        //Send C0, the version
        $stream = new Stream();

        $stream->writeByte("\x03"); //"\x03";
        $this->socketWrite($stream);

        //Send C1
        $ctime = time();
        $stream->writeInt32($ctime); //Time
        $stream->write("\x80\x00\x03\x02"); //Zero zone? Flex put : 0x80 0x00 0x03 0x02, maybe new handshake style?

        $crandom = "";
        for ($i = 0; $i < self::RTMP_SIG_SIZE - 8; $i++) {
            $crandom .= chr(rand(0, 256));
        } //TODO: better method to randomize

        $stream->write($crandom);
        $this->socketWrite($stream);

        //Read S0
        $inS0 = $this->socketRead(1)->readTinyInt();
        if ($inS0 != Message::AMF3) {
            throw new \Exception("Packet version " . $inS0 . " not supported");
        }
        ///Read S1
        $inS1 = $this->socketRead(self::RTMP_SIG_SIZE);

        //Send C2
        $outC2 = new Stream();
        $outC2->writeInt32($inS1->readInt32());
        $inS1->readInt32();
        $outC2->writeInt32($ctime);
        $raw = $inS1->readRaw();
        $outC2->write($raw);
        $this->socketWrite($outC2);

        ///Read S2
        $this->socketRead(self::RTMP_SIG_SIZE);

        //TODO check integrity

        return true;
    }

    private function sendConnectPacket($connectSettings = null, $connectParams = null)
    {
        $this->default_settings["app"] = $this->application;
        $this->default_settings["tcUrl"] = "rtmp://$this->host:$this->port/$this->application";
        
        $settings = $connectSettings;
        if (is_array($connectSettings)) {
            $settings = array_merge($this->DefaultConnectSettings, $connectSettings);
        }
        
        $this->sendOperation(
            new Operation(new Message("connect", (object) $settings, $connectParams), array($this, "onConnect"))
        );
    }

    private function handlePing(Packet $packet)
    {
        $payload = new Stream($packet->payload);
        $payload->readTinyInt();
        $type = $payload->readTinyInt();
        switch ($type) {
            case Ping::PING_CLIENT:
                $this->logger->debug("PING", array($type));
                $packet->payload[1] = chr(Ping::PONG_SERVER);
                $this->sendPacket($packet);
                break;
            default:
                $this->logger->warn("Unhandled ping package", [$type]);
                break;
        }
        unset($this->operations[$packet->chunkStreamId]);
    }

    private function handleWindowAcknowledgementSize(Packet $packet)
    {
        $this->sendPacket($packet);
        $this->logger->notice("windowAcknowledgementSize");
        unset($this->operations[$packet->chunkStreamId]);
    }

    private function handleSetPeerBandwidth(Packet $packet)
    {
        $payload = new Stream($packet->payload);
        $size = $payload->readInt32();
        $limitType = $payload->readTinyInt();
        $this->logger->notice("setPeerBandwidth", array('size' => $size, 'limitType' => $limitType));
        //TODO
        unset($this->operations[$packet->chunkStreamId]);
    }

    private function handleSetChunkSize(Packet $packet)
    {
        $payload = new Stream($packet->payload);
        $this->chunkSizeR = $payload->readInt32();
        $this->logger->notice("setChunkSize", array($this->chunkSizeR));
        unset($this->operations[$packet->chunkStreamId]);
    }

    private function handleInvoke(Packet $packet)
    {
        /**
         * @var Operation $op
         */
        $operation = $this->operations[$packet->chunkStreamId];
        $operation->getResponse()->decode($packet);

        if ($operation->getCall() && $operation->getResponse()->isResponseCommand()) {
            //Result
            unset($this->operations[$packet->chunkStreamId]);
            $operation->invokeHandler();
            $data = $operation->getResponse()->getData();
            if ($operation->getResponse()->isError()) {
                $data = (object) $data;
                $msg = $data->description;
                if (isset($data->application) && !empty($data->application)) {
                    $msg .= " (Application specific message: {$data->application})";
                }
                throw new RemoteException($msg);
            }
            return $data;
        } else {

            //Remote invoke from server
            $method = $operation->getResponse()->commandName;
            $this->logger->notice("Remote invoke from server", array($method));
            if ($this->client) {
                $handler = array($this->client, $method);
                $data = $operation->getResponse()->getData();
                is_callable($handler) && call_user_func($handler, $data);
            }
            $operation->clearResponse();
            return;
        }
    }

    //------------------------------------
    //	Internal handlers
    //------------------------------------
    /**
     * On connect handler
     * @internal
     * @param Message $m
     */
    public function onConnect(Operation $operation)
    {
        $this->logger->info("Connected");
        $this->connected = true;
        unset($this->prevSendingPacket[$operation->getResponse()->getPacket()->chunkStreamId]);
    }
}
