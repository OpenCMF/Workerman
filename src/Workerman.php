<?php

namespace opencmf\workerman;

use opencmf\core\App;
use opencmf\core\Context;
use opencmf\core\exception\EndException;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Protocols\Http\Response as WorkerResponse;

class Workerman
{
    public static function run()
    {
        define('MK_IS_WORKER', 1);
        try {
            App::init();
        }catch (\Exception $e){
            echo $e->getMessage();
        }

        $http_worker = new Worker("http://0.0.0.0:2345");
        $worker_runtime_dir = MK_ROOT_PATH . '/runtime/worker/';
        if (!is_dir($worker_runtime_dir)) {
            mkdir($worker_runtime_dir, 0755, true);
        }
        $http_worker->count = 2; // 进程数
        Worker::$stdoutFile = MK_ROOT_PATH . '/runtime/worker/run.log';
        Worker::$pidFile = MK_ROOT_PATH . '/runtime/worker/worker.pid';
        Worker::$logFile = MK_ROOT_PATH . '/runtime/worker/worker.log';

        if (function_exists('swoole_version') && version_compare(swoole_version(), '4.6.0', '>=')) {
            Worker::$eventLoopClass = \Workerman\Events\Swoole::class; //使用swoole事件循环驱动
            //\Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);
            //对指定的一些组件开启协程，避免开启所有后，mysql、redis也走协程
            \Co::set(['hook_flags' => SWOOLE_HOOK_CURL]);
            \Co::set(['hook_flags' => SWOOLE_HOOK_NATIVE_CURL]);
            \Co::set(['hook_flags' => SWOOLE_HOOK_FILE]);
            define('MK_IS_WORKER_SWOOLE', 1);
        }

// 接收到浏览器发送的数据时回复hello world给浏览器
        $http_worker->onMessage = function (TcpConnection $connection, Request $request) {
//            $connection->send('<h1>Hello Workerman. #' . rand(1000, 9999) . '</h1>');
//            return;
            $GLOBALS['begin_time'] = microtime(true);
            static $request_count;
            $uri = $request->uri();
            //实现静态文件处理
            if (strpos($uri, '.') && stripos($uri, '.php') === false) {
                if (strpos($uri, '/.') !== false || strpos($uri, '..') !== false) {
                    $connection->send(new WorkerResponse(404, [], ''));
                }
                if (stripos($uri, '.log') || stripos($uri, '.pid')) {
                    $connection->send('');
                }
                $static_file = MK_ROOT_PATH . substr($request->uri(), strlen(MK_SITE_DIR));
                if (strpos($static_file, '?')) {
                    $static_file = strstr($static_file, '?', true);
                }
                if (file_exists($static_file)) {
                    if (stripos($static_file, '.css')) {
                        $file_type = 'text/css';
                    } else if (stripos($static_file, '.js')) {
                        $file_type = 'application/javascript';
                    } else {
                        $file_type = mime_content_type($static_file);
                    }

                    $connection->send(new WorkerResponse(200, ['Content-Type' => $file_type, 'Access-Control-Allow-Origin' => '*'], file_get_contents($static_file)));
                    return;
                }
            }
            $request_count++;

            self::requestInit($request);//初始化workerman的请求数据到框架的请求对象里面

            try {
                App::run();
            } catch (EndException $e) {

            }

            $response = new WorkerResponse(response()->getStatusCode(), response()->getheaders(), response()->getBody());

            if (!empty(response()->cookies)) {
                foreach (response()->cookies as $k => $v) {
                    $response->cookie($k, $v['value'], $v['expire'], $v['path'], $v['domain'], $v['secure'], $v['httponly'], $v['samesite'],);
                }
            }
            Context::$pool = [];//删除当前请求下的实例，方便下次请求重新实例化
            $connection->send($response);
            response()->finish();//
//            if(++$request_count > 10000) {
//                // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
//                Worker::stopAll();
//            }
        };

        $http_worker->onWorkerStart = function (Worker $worker) {
            echo "Worker {$worker->id} starting...\n";
            $check_file = MK_ROOT_PATH . '/runtime/reload.log';
            $GLOBALS['data']['worker_start_key'] = file_get_contents($check_file);

            if (file_exists($check_file)) {
                Timer::add(3, function () use ($check_file, $worker) {
                    $key = file_get_contents($check_file);
                    if ($key !== $GLOBALS['data']['worker_start_key']) {
                        echo "Worker ID:" . $worker->id . " stop\n";
                        Worker::stopAll();
                        //posix_kill(posix_getppid(), SIGUSR1);
                    }
                });
            } else {
                echo 'check 文件不存在';
            }
        };

// 运行worker
        Worker::runAll();

    }

    public static function requestInit($request)
    {
        request()->requestTime = microtime(1);
        request()->server = $_SERVER;
        request()->queries = $request->queryString();
        request()->get = $request->get();
        request()->post = $request->post();
        request()->cookie = $request->cookie();
        request()->files = $request->file();
        request()->id = 1;
        request()->host = $request->host();
        request()->body = $request->rawBody();
        request()->headers = $request->header();
        if (isset(request()->headers['user-agent'])) {
            request()->userAgent = request()->headers['user-agent'];
        }
        request()->uri = $request->uri();
        request()->method = $request->method();
    }


}