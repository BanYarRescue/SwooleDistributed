<?php
/**
 * Created by PhpStorm.
 * User: tmtbe
 * Date: 16-7-20
 * Time: 下午1:39
 */

namespace Server;


class SwooleMarco
{
    /**
     * encode 头长度
     */
    const HEADER_LENGTH = 4;
    /**
     * 获取服务器ID
     */
    const MSG_TYPE_USID = -1;
    /**
     * 发送消息
     */
    const MSG_TYPE_SEND = 0;
    /**
     * 批量发消息
     */
    const MSG_TYPE_SEND_BATCH = 1;
    /**
     * 全服广播
     */
    const MSG_TYPE_SEND_ALL = 2;
    /**
     * 发送给群
     */
    const MSG_TYPE_SEND_GROUP = 3;
    
    /**
     * REDIS 异步回调消息
     */
    const MSG_TYPE_REDIS_MESSAGE = 6000;
    /**
     * MYSQL 异步回调消息
     */
    const MSG_TYPE_MYSQL_MESSAGE = 6001;

    /**
     * 添加server
     */
    const ADD_SERVER = 3003;

    /**
     * task任务
     */
    const SERVER_TYPE_TASK = 500;
    /**
     * 添加dispatch
     */
    const ADD_DISPATCH_CLIENT = 2001;
    /**
     * 移除dispatch
     */
    const REMOVE_DISPATCH_CLIENT = 2002;

    /**
     * redis uid和全局usid映射表的hashkey
     * @var string
     */
    const redis_uid_usid_hash_name = '@server_uid_usid';

    /**
     *  redis group前缀
     */
    const redis_group_hash_name_prefix = '@server_group_';
}