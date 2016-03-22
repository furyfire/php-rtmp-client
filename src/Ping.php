<?php

namespace RTMP;

/**
 * Description of the various RTMP Ping packages
 * @see http://red5.org/javadoc/red5-server-common/serialized-form.html#org.red5.server.net.rtmp.event.Ping
 * @see https://github.com/Red5/red5-server-common/blob/master/src/main/java/org/red5/server/net/rtmp/event/Ping.java
 * 
 */
class Ping
{
    //ping types

    /**
     * Stream begin / clear event
     */
    const STREAM_BEGIN = 0;

    /**
     * Stream EOF, playback of requested stream is completed.
     */
    const STREAM_PLAYBUFFER_CLEAR = 1;

    /**
     * Stream is empty
     */
    const STREAM_DRY = 2;

    /**
     * Client buffer. Sent by client to indicate its buffer time in milliseconds.
     */
    const CLIENT_BUFFER = 3;

    /**
     * Recorded stream. Sent by server to indicate a recorded stream.
     */
    const RECORDED_STREAM = 4;

    /**
     * One more unknown event
     */
    const UNKNOWN_5 = 5;

    /**
     * Client ping event. Sent by server to test if client is reachable.
     */
    const PING_CLIENT = 6;

    /**
     * Server response event. A clients ping response.
     */
    const PONG_SERVER = 7;

    /**
     * One more unknown event
     */
    const UNKNOWN_8 = 8;

    /**
     * SWF verification ping 0x001a
     */
    const PING_SWF_VERIFY = 26;

    /**
     * SWF verification pong 0x001b
     */
    const PONG_SWF_VERIFY = 27;

    /**
     * Buffer empty.
     */
    const BUFFER_EMPTY = 31;

    /**
     * Buffer full.
     */
    const BUFFER_FULL = 32;
}
