<?php
/**
 * yiiplus/yii2-websocket
 *
 * @category  PHP
 * @package   Yii2
 * @copyright 2018-2019 YiiPlus Ltd
 * @license   https://github.com/yiiplus/yii2-websocket/licence.txt Apache 2.0
 * @link      http://www.yiiplus.com
 */

namespace yiiplus\websocket\swoole;

use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yiiplus\websocket\cli\Command as CliCommand;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;

/**
 * WebSocket Server Command
 *
 * @property Server $_server       WebSocket Server
 *
 * @author gengxiankun <gengxiankun@126.com>
 * @since 1.0.0
 */
class Command extends CliCommand
{
    /**
     * @var Server
     */
    protected $_server;

    /**
     * 启动 WebSocket Server
     *
     * @return null
     */
    public function actionStart()
    {
        $this->_server = new Server($this->host, $this->port);

        $this->_server->on('handshake', [$this, 'user_handshake']);

        $this->_server->on('open', [$this, 'open']);

        $this->_server->on('message', [$this, 'message']);

        $this->_server->on('close', [$this, 'close']);

        echo '[info] websocket service has started, host is ' . $this->host . ' port is ' . $this->port . PHP_EOL;

        $this->_server->start();
    }

    /**
     * WebSocket建立连接后进行握手，通过onHandShake事件回调
     *
     * @param Request  $request  Websocket请求
     * @param Response $response Websocket响应
     *
     * @return bool 握手状态
     */
    public function user_handshake(Request $request, Response $response)
    {
        $sec_websocket_key = $request->header['sec-websocket-key'] ?? null;

        //自定定握手规则，没有设置则用系统内置的（只支持version:13的）
        if (!isset($sec_websocket_key))
        {
            //'Bad protocol implementation: it is not RFC6455.'
            $response->end();
            return false;
        }
        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $sec_websocket_key)
            || 16 !== strlen(base64_decode($sec_websocket_key))
        )
        {
            //Header Sec-WebSocket-Key is illegal;
            $response->end();
            return false;
        }

        $key = base64_encode(sha1($sec_websocket_key
            . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true));
        $headers = array(
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => $key,
            'Sec-WebSocket-Version' => '13',
            'KeepAlive'             => 'off',
        );
        foreach ($headers as $key => $val)
        {
            $response->header($key, $val);
        }
        $response->status(101);
        $response->end();

        // 直接触发 open 事件
        $this->open($this->_server, $request);

        return true;
    }

    /**
     * WebSocket建立连接后进行握手
     *
     * @param Server  $server  WebSocket Server对象
     * @param Request $request Websocket请求
     *
     * @return null
     */
    public function open(Server $server, Request $request)
    {
        echo '[info] handshake success with fd-' . $request->fd . PHP_EOL;
    }

    /**
     * 接收到客户端发来的消息
     *
     * @param Server $server WebSocket Server对象
     * @param Frame  $frame  Websocket响应
     *
     * @return null
     */
    public function message(Server $server, Frame $frame)
    {
        echo '[info] received ' . $frame->data . PHP_EOL;
        try {
            $message = json_decode($frame->data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON format');
            }

            if (!isset($message['type']) || !isset($message['data']) || !is_array($message['data'])) {
                throw new Exception('The data format is incorrect');
            }

            if (isset($this->channelClasses[$message['type']])) {
                if (!isset($this->channelClasses[$message['type']]['class'])) {
                    throw new InvalidArgumentException("channel's class is not set");
                }
                $result = (new $this->channelClasses[$message['type']]['class'])->execute($frame->fd, $message['data']);
                list($fds, $data) = $result;
                $data = json_encode($data);
                foreach ($fds as $fd) {
                    $server->push($fd, $data);
                    echo '[success] client_id ' . $fd . ' send success.' . PHP_EOL;
                }

            } else {
                throw new Exception('The channel does not exist');
            }
        } catch (\Exception $e) {
            echo '[error] '.$e->getMessage(). PHP_EOL;
        }
    }

    /**
     * 客户端断开连接
     *
     * @param Server  $server WebSocket Server对象
     * @param integer $fd     连接标识
     *
     * @return null
     */
    public function close(Server $server, int $fd)
    {
        $this->triggerClose($fd);
        echo '[info] client-' . $fd . ' is closed' . PHP_EOL;
    }
}