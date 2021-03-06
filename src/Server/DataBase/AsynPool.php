<?php
/**
 * Created by PhpStorm.
 * User: tmtbe
 * Date: 16-7-25
 * Time: 上午11:40
 */

namespace Server\DataBase;


use Noodlehaus\Config;

abstract class AsynPool implements IAsynPool
{
    const MAX_TOKEN = 655360;
    protected $commands;
    protected $pool;
    protected $callBacks;
    protected $worker_id;
    protected $server;
    protected $swoole_server;
    protected $token = 0;
    /**
     * @var AsynPoolManager
     */
    protected $asyn_manager;
    /**
     * @var Config
     */
    protected $config;

    public function __construct()
    {
        $this->callBacks = new \SplFixedArray(self::MAX_TOKEN);
        $this->commands = new \SplQueue();
        $this->pool = new \SplQueue();
    }

    function addTokenCallback($callback)
    {
        $token = $this->token;
        $this->callBacks[$token] = $callback;
        $this->token++;
        if ($this->token >= self::MAX_TOKEN) {
            $this->token = 0;
        }
        return $token;
    }
    /**
     * 分发消息
     * @param $data
     */
    function distribute($data)
    {
        $callback = $this->callBacks[$data['token']];
        unset($this->callBacks[$data['token']]);
        if($callback!=null) {
            call_user_func($callback, $data['result']);
        }
    }

    /**
     * @param $swoole_server
     * @param $asyn_manager
     */
    public function server_init($swoole_server,$asyn_manager)
    {
        $this->config = $swoole_server->config;
        $this->swoole_server = $swoole_server;
        $this->server = $swoole_server->server;
        $this->asyn_manager = $asyn_manager;
    }

    /**
     * @param $workerid
     */
    function worker_init($workerid)
    {
        $this->worker_id = $workerid;
    }

    /**
     * @param $client
     */
    function pushToPool($client)
    {
        $this->pool->push($client);
        if (count($this->commands) > 0) {//有残留的任务
            $command = $this->commands->shift();
            $this->execute($command);
        }
    }
}