<?php

define('__ROOT__', realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR);

new Server();

class Server 
{   
    /**
     * 当前的server
     * @var object swoole_server
     */
    protected $serv;

    protected $fds;

    protected $clients;

    /**
     * 服务器配置
     * @var array 
     */
    protected $config = [
            'ip' => '127.0.0.1',
            'serv' => '0.0.0.0',
            'port' => 9390,
            'config' => [
                //'daemonize' => true,        
                'worker_num' => 3,
                'max_request' => 50000,
                'task_worker_num' => 1,
                'task_max_request' => 50000,
                'heartbeat_idle_time' => 30,
                'heartbeat_check_interval' => 2,
                'dispatch_mode' => 2,
                'package_max_length' => 2000000,
                'socket_buffer_size' => 64 * 1024 *1024,
                'log_file' => 'error.log',
            ]
        ];

    /**
     * @param array $config 配置文件
     * @param string $servName 需要启动的服务名
     * @param array $argv 用户参数
     */
    public function __construct()
    {   
        $this->serv = new swoole_server($this->config['serv'], $this->config['port']);
        $this->serv->set($this->config['config']);

        $this->serv->on('Start', [$this, 'onStart']);
        $this->serv->on('Shutdown', [$this, 'onShutdown']);
        $this->serv->on('Receive', [$this, 'onReceive']);
        $this->serv->on('Connect', [$this, 'onConnect']);
        $this->serv->on('Task', [$this, 'onTask']);
        $this->serv->on('Finish', [$this, 'onFinish']);
        $this->serv->on('Close', [$this, 'onClose']);

        $this->serv->start();
    }

    /**
     * 服务启动回调事件
     * @param  swoole_server $serv
     */
    public function onStart(swoole_server $serv)
    {
        if (PHP_OS !== 'Darwin') {
            swoole_set_process_name("php {$this->servName}: master");
        }


        $ipList = swoole_get_local_ip();
        $ipList = implode(", ", $ipList);
        echo "start http proxy on ".$ipList.":".$this->config['port'], PHP_EOL;
    }

    /**
     * 服务关闭回调事件
     * @param  swoole_server $serv
     */
    public function onShutdown(swoole_server $serv)
    {
        echo "http proxy Shutdown...", PHP_EOL;
    }

    /**
     * 接受到数据包回调事件
     * @param  swoole_server $serv
     * @param  int $fd
     * @param  int $fromId
     * @param  string 数据包
     */
    public function onReceive(swoole_server $serv, $fd, $fromId, $data)
    {
        try {
            //如果是https请求
            if (isset($this->clients[$fd])) {
                if ($this->clients[$fd]->isConnected()) $this->clients[$fd]->send($data);
                return;
            }

            $this->clients[$fd] = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            $this->clients[$fd]->on("receive", function(swoole_client $cli, $res) use ($serv, $fd) {
                $this->sendData($serv, $fd, $res);
            });
            $this->clients[$fd]->on("error", function(swoole_client $cli){
                echo "error\n";
            });
            $this->clients[$fd]->on("close", function(swoole_client $cli) use ($serv, $fd) {
                echo "proxy finish\n";
                $serv->close($fd);
            });

            $httpRequest = $this->parse($data);
            if (!$httpRequest || !isset($httpRequest['host']) || $httpRequest['host'] == "") {
                $serv->close($fd);
                return;
            }
            
            if ($httpRequest['isHttps']) {
                $this->clients[$fd]->on("connect", function(swoole_client $cli) use ($serv, $fd) {
                    echo "proxy https connect\n";
                    $this->sendData($serv, $fd, "HTTP/1.1 200 Connection Established\r\n\r\n");
                });
            } else {
                $buffer = $httpRequest['buf'];
                $this->clients[$fd]->on("connect", function(swoole_client $cli) use ($buffer) {
                    //直接转发数据
                    echo "proxy http connect\n";
                    $cli->send($buffer);
                });
            }

            $this->clients[$fd]->connect($httpRequest['host'], intval($httpRequest['port']), 2, 0);

        } catch (\Exception $e) {
            $this->record([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'type'    => $e->getCode(),
            ]);
        }
    }

    public function parse($data)
    {
        $httpRequest = [];
        $httpRequest['buf'] = $data;
        $httpRequest['isHttps'] = false;
        $protocol = explode("\r\n", $data);
        if (isset($protocol[0]) && $protocol[0] != "") {
            $pro = explode(" ", $protocol[0]);
            if (count($pro) < 3) return $httpRequest;
            //print_r(explode(" ", $protocol[0]));
            list($httpRequest['method'], $httpRequest['url'], $httpRequest['protocol']) = explode(" ", $protocol[0]);
            $httpRequest['port'] = 80;
        }

        foreach ($protocol as $v) {
            if (strrpos($v, "Host:") !== false) {
                list($h, $httpRequest['host']) = explode(":", trim($v));
            }
        }
        $httpRequest['host'] = trim($httpRequest['host']);
        //$httpRequest['ip'] = gethostbyname($httpRequest['host']);

        if (strtoupper($httpRequest['method']) == "CONNECT") {
            $httpRequest['isHttps'] = true;
            $httpRequest['port'] = 443;
        }
        
        return $httpRequest;
    }

    
    public function onConnect(swoole_server $serv, $fd, $fromId)
    {   
        try {
            $this->fds[$fd] = $fd;
        } catch (\Exception $e) {
            $this->record([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'type'    => $e->getCode(),
            ]);
        }
    }
    /**
     * 触发Task任务的回调事件
     * @param  swoole_server
     * @param  swoole_server $serv
     * @param  int $fd
     * @param  int $fromId
     * @param  string 数据包
     * @return array
     */
    public function onTask(swoole_server $serv, $fd, $fromId, $data)
    {   
        try {
        } catch (\Exception $e) {
            $this->record([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'type'    => $e->getCode(),
            ]);
        }
    }

    /**
     * Task任务完成的回调事件
     * @param  swoole_server
     * @param  swoole_server $serv
     * @param  string $data
     * @return 
     */
    public function onFinish(swoole_server $serv, $fd, $data)
    {
        try {
            if ($data) {
                $this->sendData($serv, $data['fd'], $data['data']);
            }
        } catch (\Exception $e) {
            $this->record([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'type'    => $e->getCode(),
            ]);
        }
    }

    /**
     * 向客户端发送数据
     * @param  swoole_server
     * @param  swoole_server $serv
     * @param  string $data
     */
    private function sendData(swoole_server $serv, $fd, $data)
    {   
        if ($data === false) {
            $data = 0;
        }

        $fdinfo = $serv->connection_info($fd);
        if($fdinfo){
            //如果这个时候客户端还连接者的话说明需要返回返回的信息,
            $serv->send($fd, $data);
        } else {
            // $this->clients[$fd]->close();
            // unset($this->clients[$fd]);
        }
    }

    public function onClose(swoole_server $serv, $fd)
    {   
        if (isset($this->fds[$fd])) {
            unset($this->fds[$fd]);
            unset($this->clients[$fd]);
        }
    }


    /**
     * 错误记录
     * @param  Exception $e
     * @param  string $type
     */
    private function record($e, $type = 'error')
    {   
        $levels = array(
            E_WARNING => 'Warning',
            E_NOTICE => 'Notice',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Runtime Notice',
            E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
            E_ERROR => 'Error',
            E_CORE_ERROR => 'Core Error',
            E_COMPILE_ERROR => 'Compile Error',
            E_PARSE => 'Parse',
        );
        if (!isset($levels[$e['type']])) {
            $level = 'Task Exception';
        } else {
            $level = $levels[$e['type']];
        }
        echo '[' . $level . '] ' . $e['message'] . '[' . $e['file'] . ' : ' . $e['line'] . ']';
    }
}
