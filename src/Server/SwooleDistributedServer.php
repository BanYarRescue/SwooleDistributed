<?php
namespace Server;

use Noodlehaus\Exception;
use Server\CoreBase\ControllerFactory;
use Server\CoreBase\Loader;
use Server\CoreBase\SwooleException;
use Server\DataBase\AsynPoolManager;
use Server\DataBase\MysqlAsynPool;
use Server\DataBase\RedisAsynPool;
use Server\Pack\IPack;
use Server\Route\IRoute;

/**
 * Created by PhpStorm.
 * User: tmtbe
 * Date: 16-7-14
 * Time: 上午9:18
 */
class SwooleDistributedServer extends SwooleServer
{
    /**
     * 实例
     * @var SwooleServer
     */
    private static $instance;
    /**
     * 分布式系统服务器唯一标识符
     * @var int
     */
    private $USID;
    /**
     * dispatch fd
     * @var array
     */
    protected $dispatchClientFds = [];

    /**
     * @var \Redis
     */
    public $redis_client;
    /**
     * @var RedisAsynPool
     */
    public $redis_pool;
    /**
     * @var MysqlAsynPool
     */
    public $mysql_pool;
    /**
     * @var AsynPoolManager
     */
    private $asnyPoolManager;
    /**
     * 多少人启用task进行发送
     * @var
     */
    private $send_use_task_num;

    /**
     * 加载器
     * @var Loader
     */
    public $loader;

    /**
     * 封包器
     * @var IPack
     */
    public $pack;
    /**
     * 路由器
     * @var IRoute
     */
    public $route;

    /**
     * @var dispatch 端口
     */
    protected $dispatch_port;

    /**
     * 共享内存表
     * @var \swoole_table
     */
    protected $uid_fd_table;

    /**
     * 连接池进程
     * @var
     */
    protected $pool_process;

    /**
     * SwooleDistributedServer constructor.
     */
    public function __construct()
    {
        self::$instance =& $this;
        parent::__construct();
        $this->loader = new Loader();
        $pack_class_name = "\\Server\\Pack\\" . $this->config['server']['pack_tool'];
        $this->pack = new $pack_class_name;
        $route_class_name = "\\Server\\Route\\" . $this->config['server']['route_tool'];
        $this->route = new $route_class_name;
    }

    /**
     * 设置配置
     */
    public function setConfig()
    {
        $this->socket_type = SWOOLE_SOCK_TCP;
        $this->socket_name = $this->config['server']['socket'];
        $this->port = $this->config['server']['port'];
        $this->name = $this->config['server']['name'];
        $this->user = $this->config->get('server.set.user', '');
        $this->worker_num = $this->config['server']['set']['worker_num'];
        $this->task_num = $this->config['server']['set']['task_worker_num'];
        $this->send_use_task_num = $this->config['server']['send_use_task_num'];
    }

    /**
     * 设置服务器配置参数
     * @return array
     */
    public function setServerSet()
    {
        $set = $this->config['server']['set'];
        $set = array_merge($set, $this->probuf_set);
        return $set;
    }

    /**
     * 开始前创建共享内存保存USID值
     */
    public function beforeSwooleStart()
    {
        //创建uid->fd共享内存表
        $this->uid_fd_table = new \swoole_table(65536);
        $this->uid_fd_table->column('fd', \swoole_table::TYPE_INT, 8);
        $this->uid_fd_table->create();
        //创建redis，mysql异步连接池进程
        if($this->config['asyn_process_enable']) {//代表启动单独进程进行管理
            $this->pool_process = new \swoole_process(function ($process) {
                $this->asnyPoolManager = new AsynPoolManager($process, $this);
                $this->asnyPoolManager->event_add();
                $this->asnyPoolManager->registAsyn(new RedisAsynPool());
                $this->asnyPoolManager->registAsyn(new MysqlAsynPool());
            }, false, 2);
            $this->server->addProcess($this->pool_process);
        }
        //创建第二个端口用于连接dispatch
        $this->dispatch_port = $this->server->listen($this->config['server']['socket'], $this->config['server']['dispatch_port'], SWOOLE_SOCK_TCP);
        $this->dispatch_port->on('connect', function ($serv, $fd) {
            print_r("Find a new dispatcher.\n");
            for ($i = 0; $i < $this->worker_num + $this->task_num; $i++) {
                if ($i == $serv->worker_id) continue;
                $data = $this->packSerevrMessageBody(SwooleMarco::ADD_DISPATCH_CLIENT, $fd);
                $serv->sendMessage($data, $i);
            }
            $this->dispatchClientFds[$fd] = $fd;
        });
        $this->dispatch_port->on('close', function ($serv, $fd) {
            print_r("Remove a dispatcher.\n");
            for ($i = 0; $i < $this->worker_num + $this->task_num; $i++) {
                if ($i == $serv->worker_id) continue;
                $data = $this->packSerevrMessageBody(SwooleMarco::REMOVE_DISPATCH_CLIENT, $fd);
                $serv->sendMessage($data, $i);
            }
            unset($this->dispatchClientFds[$fd]);
        });
        $this->dispatch_port->on('receive', function ($serv, $fd, $from_id, $data) {
            $data = substr($data, SwooleMarco::HEADER_LENGTH);
            $unserialize_data = unserialize($data);
            $type = $unserialize_data['type'];
            $message = $unserialize_data['message'];
            switch ($type) {
                case SwooleMarco::MSG_TYPE_USID://获取服务器唯一id
                    if ($this->USID != $message) {
                        for ($i = 0; $i < $this->worker_num + $this->task_num; $i++) {
                            if ($i == $serv->worker_id) continue;
                            $serv->sendMessage($data, $i);
                        }
                        $this->USID = $message;
                    }
                    break;
                case SwooleMarco::MSG_TYPE_SEND://发送消息
                    $this->sendToUid($message['uid'], $message['data'], true);
                    break;
                case SwooleMarco::MSG_TYPE_SEND_BATCH://批量消息
                    $this->sendToUids($message['uids'], $message['data'], true);
                    break;
                case SwooleMarco::MSG_TYPE_SEND_ALL://广播消息
                    $serv->task($data);
                    break;
            }
        });
    }

    /**
     * PipeMessage
     * @param $serv
     * @param $from_worker_id
     * @param $message
     */
    public function onSwoolePipeMessage($serv, $from_worker_id, $message)
    {
        parent::onSwoolePipeMessage($serv, $from_worker_id, $message);
        $data = unserialize($message);
        switch ($data['type']) {
            case SwooleMarco::MSG_TYPE_USID:
                $this->USID = $data['message'];
                break;
            case SwooleMarco::ADD_DISPATCH_CLIENT:
                $fd = $data['message'];
                $this->dispatchClientFds[$fd] = $fd;
                break;
            case SwooleMarco::REMOVE_DISPATCH_CLIENT:
                unset($this->dispatchClientFds[$data['message']]);
                break;
            case SwooleMarco::MSG_TYPE_REDIS_MESSAGE:
                $this->asnyPoolManager->distribute($data['message']);
                break;
        }
    }

    /**
     * 随机选择一个dispatch发送消息
     * @param $data
     */
    private function sendToDispatchMessage($data)
    {
        $fd = array_rand($this->dispatchClientFds);
        if ($fd != null) {
            $this->server->send($fd, $this->encode($data));
        }
    }

    /**
     * task异步任务
     * @param $serv
     * @param $task_id
     * @param $from_id
     * @param $data
     * @return mixed
     */
    public function onSwooleTask($serv, $task_id, $from_id, $data)
    {
        if (is_string($data)) {
            $unserialize_data = unserialize($data);
        } else {
            $unserialize_data = $data;
        }
        $type = $unserialize_data['type']??'';
        $message = $unserialize_data['message']??'';
        switch ($type) {
            case SwooleMarco::MSG_TYPE_SEND_BATCH://发送消息
                foreach ($message['fd'] as $fd) {
                    $this->server->send($fd, $message['data']);
                }
                return null;
            case SwooleMarco::MSG_TYPE_SEND_ALL://发送广播
                foreach ($serv->connections as $fd) {
                    if (in_array($fd, $this->dispatchClientFds)) {
                        continue;
                    }
                    $serv->send($fd, $message['data']);
                }
                return null;
            case SwooleMarco::SERVER_TYPE_TASK://task任务
                $task_name = $message['task_name'];
                $task = $this->loader->task($task_name);
                $task_fuc_name = $message['task_fuc_name'];
                $task_data = $message['task_fuc_data'];
                $result = call_user_func_array(array($task, $task_fuc_name), $task_data);
                $task->distory();
                return $result;
            default:
                return parent::onSwooleTask($serv, $task_id, $from_id, $data);
        }
    }

    /**
     * 重写onSwooleWorkerStart方法，添加异步redis
     * @param $serv
     * @param $workerId
     */
    public function onSwooleWorkerStart($serv, $workerId)
    {
        echo "WorkerId:$workerId Initialization please wait a moment.\n";
        if (!$serv->taskworker) {
            //异步redis连接池
            $this->redis_pool = new RedisAsynPool();
            $this->redis_pool->worker_init($workerId);
            //异步mysql连接池
            $this->mysql_pool = new MysqlAsynPool();
            $this->mysql_pool->worker_init($workerId);
            //注册
            $this->asnyPoolManager = new AsynPoolManager($this->pool_process,$this);
            if(!$this->config['asyn_process_enable']){
                $this->asnyPoolManager->no_event_add();
            }
            $this->asnyPoolManager->registAsyn($this->redis_pool);
            $this->asnyPoolManager->registAsyn($this->mysql_pool);
        }else {
            //同步redis连接，用于存储
            $this->redis_client = new \Redis();
            if ($this->redis_client->pconnect($this->config['redis']['ip'], $this->config['redis']['port']) == false) {
                throw new SwooleException($this->redis_client->getLastError());
            }
            if ($this->redis_client->auth($this->config['redis']['password']) == false) {
                throw new SwooleException($this->redis_client->getLastError());
            }
            $this->redis_client->select($this->config['redis']['select']);
        }
        //定时器
        if ($workerId == $this->worker_num - 1) {//最后一个worker处理启动定时器
            $timer_tasks = $this->config->get('timerTask');
            $timer_tasks_used = array();
            foreach ($timer_tasks as $timer_task) {
                $task_name = $timer_task['task_name'];
                $method_name = $timer_task['method_name'];
                if (!key_exists('start_time', $timer_task)) {
                    $start_time = -1;
                } else {
                    $start_time = strtotime(date($timer_task['start_time']));
                }
                if (!key_exists('end_time', $timer_task)) {
                    $end_time = -1;
                } else {
                    $end_time = strtotime(date($timer_task['end_time']));
                }
                $interval_time = $timer_task['interval_time'] < 1 ? 1 : $timer_task['interval_time'];
                $max_exec = $timer_task['max_exec']??-1;
                $timer_tasks_used[] = [
                    'task_name' => $task_name,
                    'method_name' => $method_name,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'interval_time' => $interval_time,
                    'max_exec' => $max_exec,
                    'now_exec' => 0
                ];
            }
            if (count($timer_tasks_used) > 0) {
                $serv->tick(1000, function () use (&$timer_tasks_used) {
                    $time = time();
                    foreach ($timer_tasks_used as &$timer_task) {
                        if ($timer_task['start_time'] < $time && $timer_task['start_time'] != -1) {
                            $count = round(($time - $timer_task['start_time']) / $timer_task['interval_time']);
                            $timer_task['start_time'] += $count * $timer_task['interval_time'];
                        }
                        if (($time == $timer_task['start_time'] || $timer_task['start_time'] == -1) &&
                            ($time < $timer_task['end_time'] || $timer_task['end_time'] = -1) &&
                            ($timer_task['now_exec'] < $timer_task['max_exec'] || $timer_task['max_exec'] == -1)
                        ) {
                            $timer_task['now_exec']++;
                            if ($timer_task['start_time'] == -1) $timer_task['start_time'] = $time;
                            $timer_task['start_time'] += $timer_task['interval_time'];
                            $task = $this->loader->task($timer_task['task_name']);
                            call_user_func([$task, $timer_task['method_name']]);
                            $task->startTask(null);
                        }
                    }
                });
            }
        }
        echo "WorkerId:$workerId Server is ready.\n";
        parent::onSwooleWorkerStart($serv, $workerId);
    }

    /**
     * 客户端有消息时
     * @param $serv
     * @param $fd
     * @param $from_id
     * @param $data
     */
    public function onSwooleReceive($serv, $fd, $from_id, $data)
    {
        parent::onSwooleReceive($serv, $fd, $from_id, $data);
        $data = substr($data, SwooleMarco::HEADER_LENGTH);//去掉头
        //反序列化
        $client_data = $this->pack->unPack($data);
        //client_data进行处理
        $client_data = $this->route->handleClientData($client_data);
        $controller_name = $this->route->getControllerName();
        $controller_instance = ControllerFactory::getInstance()->getController($controller_name);
        if ($controller_instance != null) {
            $uid = $serv->connection_info($fd)['uid']??0;
            $controller_instance->setClientData($uid, $fd, $client_data);
            $methd_name = $this->route->getMethodName();
            if (method_exists($controller_instance, $methd_name)) {
                call_user_func([$controller_instance, $methd_name]);
            }
        }
    }

    /**
     * 连接断开
     * @param $serv
     * @param $fd
     */
    public function onSwooleClose($serv, $fd)
    {
        $info = $serv->connection_info($fd, 0, true);
        $uid = $info['uid']??0;
        if (!empty($uid)) {
            $this->unBindUid($uid);
        }
        parent::onSwooleClose($serv, $fd);
    }

    /**
     * 将fd绑定到uid,uid不能为0
     * @param $fd
     * @param $uid
     */
    public function bindUid($fd, $uid)
    {
        //将这个fd与当前worker进行绑定
        $this->server->bind($fd, $uid);
        //建立映射表
        if (!empty($this->USID)) {
            if ($this->redis_client != null) {
                $this->redis_client->hSet(SwooleMarco::redis_uid_usid_hash_name, $uid, $this->USID);
            } else {
                $this->redis_pool->hSet(SwooleMarco::redis_uid_usid_hash_name, $uid, $this->USID, null);
            }
        }
        //加入共享内存
        $this->uid_fd_table->set($uid, ['fd' => $fd]);
    }

    /**
     * 解绑uid，链接断开自动解绑
     * @param $uid
     */
    public function unBindUid($uid)
    {
        //更新映射表
        if ($this->redis_client != null) {
            $this->redis_client->hDel(SwooleMarco::redis_uid_usid_hash_name, $uid);
        } else {
            $this->redis_pool->hDel(SwooleMarco::redis_uid_usid_hash_name, $uid, null);
        }
        //更新共享内存
        $this->uid_fd_table->del($uid);
    }

    /**
     * uid是否在线(异步时候需要提供callback,task可以直接返回结果)
     * @param $uid
     * @param $callback
     * @return bool
     */
    public function uidIsOnline($uid, $callback)
    {
        if ($this->redis_client) {
            return $this->redis_client->hExists(SwooleMarco::redis_uid_usid_hash_name, $uid);
        } else {
            $this->redis_pool->hExists(SwooleMarco::redis_uid_usid_hash_name, $uid, $callback);
        }
    }

    /**
     * 获取在线人数(异步时候需要提供callback,task可以直接返回结果)
     * @param $callback
     * @return int
     */
    public function countOnline($callback)
    {
        if ($this->redis_client != null) {
            return $this->redis_client->hLen(SwooleMarco::redis_uid_usid_hash_name);
        } else {
            $this->redis_pool->hLen(SwooleMarco::redis_uid_usid_hash_name, $callback);
        }
    }

    /**
     * 添加到群
     * @param $uid int
     * @param $group_id int
     */
    public function addToGroup($uid, $group_id)
    {
        if ($this->redis_client != null) {
            $this->redis_client->hSet(SwooleMarco::redis_group_hash_name_prefix . $group_id, $uid, $uid);
        } else {
            $this->redis_pool->hSet(SwooleMarco::redis_group_hash_name_prefix . $group_id, $uid, $uid, null);
        }
    }

    /**
     * 从群里移除
     * @param $uid
     * @param $group_id
     */
    public function removeFromGroup($uid, $group_id)
    {
        if ($this->redis_client != null) {
            $this->redis_client->hDel(SwooleMarco::redis_group_hash_name_prefix . $group_id, $uid);
        } else {
            $this->redis_client->hDel(SwooleMarco::redis_group_hash_name_prefix . $group_id, $uid, null);
        }
    }

    /**
     * 删除群
     * @param $group_id
     */
    public function delGroup($group_id)
    {
        if ($this->redis_client != null) {
            $this->redis_client->del(SwooleMarco::redis_group_hash_name_prefix . $group_id);
        } else {
            $this->redis_client->del(SwooleMarco::redis_group_hash_name_prefix . $group_id, null);
        }
    }

    /**
     * 向uid发送消息
     * @param $uid
     * @param $data
     * @param $fromDispatch
     */
    public function sendToUid($uid, $data, $fromDispatch = false)
    {
        if (!$fromDispatch) {
            $data = $this->encode($this->pack->pack($data));
        }
        if ($this->uid_fd_table->exist($uid)) {//本机处理
            $fd = $this->uid_fd_table->get($uid)['fd'];
            $this->server->send($fd, $data);
        } else {
            if ($fromDispatch) return;
            $this->sendToDispatchMessage($this->packSerevrMessageBody(SwooleMarco::MSG_TYPE_SEND, ['data' => $data, 'uid' => $uid]));
        }
    }

    /**
     * 广播
     * @param $data
     */
    public function sendToAll($data)
    {
        $data = $this->encode($this->pack->pack($data));
        $this->sendToDispatchMessage($this->packSerevrMessageBody(SwooleMarco::MSG_TYPE_SEND_ALL, ['data' => $data]));
    }

    /**
     * 发送给群
     * @param $groupId
     * @param $data
     */
    public function sendToGroup($groupId, $data)
    {
        $data = $this->encode($this->pack->pack($data));
        $this->sendToDispatchMessage($this->packSerevrMessageBody(SwooleMarco::MSG_TYPE_SEND_GROUP, ['data' => $data, 'groupId' => $groupId]));
    }

    /**
     * 批量发送消息
     * @param $uids
     * @param $data
     * @param $fromDispatch
     */
    public function sendToUids($uids, $data, $fromDispatch = false)
    {
        if (!$fromDispatch) {
            $data = $this->encode($this->pack->pack($data));
        }
        $current_fds = [];
        foreach ($uids as $key => $uid) {
            if ($this->uid_fd_table->exist($uid)) {
                $current_fds[] = $this->uid_fd_table->get($uid)['fd'];
                unset($uids[$key]);
            }
        }
        if (count($current_fds) > $this->send_use_task_num) {//过多人就通过task
            $task_data = $this->packSerevrMessageBody(SwooleMarco::MSG_TYPE_SEND_BATCH, ['data' => $data, 'fd' => $current_fds]);
            $this->server->task($task_data);
        } else {
            foreach ($current_fds as $fd) {
                $this->server->send($fd, $data);
            }
        }
        if ($fromDispatch) return;
        //本机处理不了的发给dispatch
        if (count($uids) > 0) {
            $dispatch_data = $this->packSerevrMessageBody(SwooleMarco::MSG_TYPE_SEND_BATCH, ['data' => $data, 'uids' => array_values($uids)]);
            $this->sendToDispatchMessage($dispatch_data);
        }
    }

    /**
     * 获取实例
     * @return SwooleDistributedServer
     */
    public static function &get_instance()
    {
        return self::$instance;
    }
}