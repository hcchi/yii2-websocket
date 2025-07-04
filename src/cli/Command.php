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

namespace yiiplus\websocket\cli;

use yii\console\Controller;

/**
 * WebSocket Server Command
 *
 * @property object  $websocket        WebSocket compoent，通过 bootstrap 引导注入
 * @property string  $host             WebSocket服务端HOST，默认为'0.0.0.0'，此参数可以在命令行指定
 * @property integer $port             WebSocket端口号，默认为'9501'，此参数可以在命令行指定
 * @property string  $defaultAction    默认方法
 * @property array   $channelClasses   频道类配置
 *
 * @author gengxiankun <gengxiankun@126.com>
 * @since 1.0.0
 */
abstract class Command extends Controller
{
    /**
     * @var object WebSocket compoent
     */
    public $websocket;

    /**
     * @var string WebSocket host
     */
    public $host = '0.0.0.0';

    /**
     * @var integer WebSocket 端口号
     */
    public $port = 9501;

    /**
     * @var string 默认方法
     */
    public $defaultAction = 'start';

    /**
     * @var array 频道类配置
     */
    public $channelClasses = [];

    /**
     * 初始化
     */
    public function init()
    {
        parent::init();

        // 如果 websocket 组件有配置 channels，同步到 channelClasses
        if ($this->websocket && !empty($this->websocket->channels)) {
            $this->channelClasses = $this->websocket->channels;
        }
    }

    /**
     * 指定命令行参数
     *
     * @param string actionID
     *
     * @return array 返回指定的参数
     */
    public function options($actionID)
    {
        return [
            'host',
            'port'
        ];
    }

    /**
     * 为命令行的参数设置别名
     *
     * @return array 参数别名键值对
     */
    public function optionAliases()
    {
        return [
            'h' => 'host',
            'p' => 'port',
        ];
    }

    /**
     * 启动 WebSocket Server
     *
     * @return null
     */
    abstract public function actionStart();

    /**
     * 获取 WebSocket channel list
     *
     * @return null
     */
    public function actionList()
    {
        echo 'channels:' . PHP_EOL;

        $channels = $this->channelClasses ?: $this->websocket->channels;
        foreach ($channels as $key => $channel) {
            $channelInfo = is_array($channel) ? $channel['class'] : $channel;
            echo '   - ' . $key . ' => ' . $channelInfo . PHP_EOL;
        }
    }

    /**
     * 触发指定 channel 下的执行方法
     *
     * @param integer $fd   客户端连接描述符
     * @param mixed   $data 传输的数据
     *
     * @return array|false
     */
    protected function triggerMessage($fd, $data)
    {
        $class = $this->channelResolve($data);

        if (!$class) {
            return false;
        }

        $result = call_user_func([$class, 'execute'], $fd, json_decode($data));

        if (!$result) {
            return false;
        }

        list($fds, $data) = $result;

        if (!is_array($fds)) {
            $fds = [$fds];
        }

        return [$fds, $data];
    }

    /**
     * 触发所有 channels 下的 close hook
     *
     * @param integer $fd 客户端文件描述符
     *
     * @return null
     */
    protected function triggerClose($fd)
    {
        $channels = $this->channelClasses ?: $this->websocket->channels;

        foreach ($channels as $key => $channel) {
            $className = is_array($channel) ? $channel['class'] : $channel;
            $class = $this->getClass($className);
            if ($class && method_exists($class, 'close')) {
                call_user_func([$class, 'close'], $fd);
            }
        }
    }

    /**
     * channel 解析
     *
     * @param string $data 客户端传来的数据
     *
     * @return object|false channel 执行类对象
     */
    protected function channelResolve($data)
    {
        // 获取 channel
        $data = json_decode($data);
        if (!is_object($data) || !property_exists($data, 'channel')) {
            echo '[error] missing client data.' . PHP_EOL;
            return false;
        }

        $channels = $this->channelClasses ?: $this->websocket->channels;
        if (!array_key_exists($data->channel, $channels)) {
            echo '[error] channel parameter parsing failed.' . PHP_EOL;
            return false;
        }

        $channel = $channels[$data->channel];
        $className = is_array($channel) ? $channel['class'] : $channel;

        return $this->getClass($className);
    }

    /**
     * 解析类对象
     *
     * @param string $className 类名，包含命名空间
     *
     * @return bool|object 返回类对象
     *
     * @throws \ReflectionException
     */
    protected function getClass($className)
    {
        // 判断 channel 绑定的类是否存在
        if (!class_exists($className)) {
            echo '[error] ' . $className . ' class not found.' . PHP_EOL;
            return false;
        }

        // 验证 channel 类是否规范
        $reflectionClass = new \ReflectionClass($className);
        $class = $reflectionClass->newInstance();
        if (!($class instanceof \yiiplus\websocket\ChannelInterface)) {
            echo '[error] ' . $className . ' must be a ChannelInterface instance instead.' . PHP_EOL;
            return false;
        }

        return $class;
    }
}